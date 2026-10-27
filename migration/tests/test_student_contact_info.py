"""Source-level regression tests for optional student contact migrations."""

import importlib
from pathlib import Path
import re
from tempfile import TemporaryDirectory
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

from migrator import main


MASTER = importlib.import_module("migrator.migrations.master.20261024120000_student_contact_info")
COURSE = importlib.import_module("migrator.migrations.course.20261024120100_student_contact_info")
FIELDS = ("linkedin_username", "github_username", "personal_webpage")
ROOT = Path(__file__).resolve().parents[1] / "migrator"


class TestStudentContactInfo(unittest.TestCase):
    def setUp(self):
        self.config = SimpleNamespace(database={"dbname": "submitty_f26_sample"})
        self.database = Mock()

    def test_master_adds_optional_columns_without_installing_triggers(self):
        MASTER.up(self.config, self.database)
        sql = self.database.execute.call_args.args[0]
        for field in FIELDS:
            self.assertIn("ADD COLUMN IF NOT EXISTS " + field, sql)
        self.assertEqual(sql.count("NOT NULL DEFAULT ''"), 3)
        self.assertNotIn("FUNCTION", sql)

    @patch.object(COURSE, "Database")
    def test_existing_members_backfilled_with_bound_values(self, database_class):
        master = database_class.return_value
        master.table_has_column.return_value = True
        rows = [
            dict(
                user_id="student",
                linkedin_username="student-name",
                github_username="student-code",
                personal_webpage="https://example.com/?a='b',c=$contact_sync$\\%\n; DO $$BEGIN END$$;",
            ),
            dict(user_id="blank", linkedin_username="", github_username="", personal_webpage=""),
        ]
        master.execute.return_value.mappings.return_value = rows
        COURSE.up(self.config, self.database, "f26", "sample")
        database_class.assert_called_once_with({"dbname": "submitty"}, "master")
        self.assertEqual(self.config.database["dbname"], "submitty_f26_sample")
        select = master.execute.call_args.args[0]
        self.assertEqual(select.compile().params, {"term": "f26", "course": "sample"})
        self.assertIn("JOIN courses_users", str(select))
        updates = self.database.execute.call_args_list[1:]
        self.assertEqual([call.args[0].compile().params for call in updates], rows)
        master.close.assert_called_once()

    @patch.object(COURSE, "Database")
    def test_course_can_migrate_before_master(self, database_class):
        master = database_class.return_value
        master.table_has_column.return_value = False
        COURSE.up(self.config, self.database, "f26", "sample")
        self.assertEqual(self.database.execute.call_count, 1)
        master.execute.assert_not_called()
        master.close.assert_called_once()

    @patch.object(COURSE, "Database")
    def test_backfill_failure_closes_master(self, database_class):
        master = database_class.return_value
        master.table_has_column.return_value = True
        master.execute.side_effect = RuntimeError("backfill failed")
        with self.assertRaisesRegex(RuntimeError, "backfill failed"):
            COURSE.up(self.config, self.database, "f26", "sample")
        master.close.assert_called_once()

    @patch.object(COURSE, "Database")
    def test_no_members_only_adds_columns(self, database_class):
        master = database_class.return_value
        master.table_has_column.return_value = True
        master.execute.return_value.mappings.return_value = []
        COURSE.up(self.config, self.database, "f26", "sample")
        self.assertEqual(self.database.execute.call_count, 1)
        master.close.assert_called_once()

    @patch.object(COURSE, "Database")
    def test_course_write_failure_propagates_without_committing_migration(self, database_class):
        master = database_class.return_value
        master.table_has_column.return_value = True
        master.execute.return_value.mappings.return_value = [
            dict(user_id="student", linkedin_username="", github_username="", personal_webpage="")
        ]
        self.database.execute.side_effect = [None, RuntimeError("course write failed")]
        args = SimpleNamespace(
            config=self.config, fake=False, direction="up", semester="f26", course="sample"
        )
        with self.assertRaisesRegex(RuntimeError, "course write failed"):
            main.run_migration(
                self.database,
                {"id": "20261024120100", "module": COURSE},
                "course",
                args,
            )
        self.database.session.commit.assert_not_called()
        master.close.assert_called_once()

    @patch.object(COURSE, "Database")
    def test_dump_column_declarations_match_incremental_migrations(self, database_class):
        database_class.return_value.table_has_column.return_value = False
        for module, filename in [(MASTER, "submitty_db.sql"), (COURSE, "course_tables.sql")]:
            with self.subTest(schema=filename):
                self.database.reset_mock()
                if module is MASTER:
                    module.up(self.config, self.database)
                else:
                    module.up(self.config, self.database, "f26", "sample")
                ddl = self.database.execute.call_args.args[0]
                added = re.findall(
                    r"ADD COLUMN IF NOT EXISTS (\w+) character varying\((\d+)\) NOT NULL DEFAULT ''",
                    ddl,
                )
                self.assertEqual(added, list(zip(FIELDS, ["100", "39", "2048"])))
                dump = (ROOT / "data" / filename).read_text()
                table = re.search(r"CREATE TABLE public.users \((.*?)\n\);", dump, re.S).group(1)
                dumped = re.findall(
                    r"^    (\w+) character varying\((\d+)\) DEFAULT ''::character varying NOT NULL",
                    table,
                    re.M,
                )
                self.assertEqual([column for column in dumped if column[0] in FIELDS], added)
                # ALTER TABLE appends these columns after the preexisting columns.
                self.assertRegex(
                    table,
                    r"personal_webpage character varying\(2048\) DEFAULT ''::character varying NOT NULL,\n    CONSTRAINT",
                )

    def test_current_triggers_guard_remote_columns_and_match_schema_dump(self):
        dump = (ROOT / "data/submitty_db.sql").read_text()
        for name, record in [("sync_user", "NEW"), ("sync_courses_user", "user_row")]:
            sql = (ROOT / "triggers/master" / (name + ".sql")).read_text()
            with self.subTest(trigger=name):
                self.assertIn("FROM information_schema.columns", sql)
                self.assertIn("table_schema = 'public' AND table_name = 'users'", sql)
                self.assertIn("format('; DO %L;'", sql)
                self.assertIn("EXECUTE %L;", sql)
                for field in FIELDS:
                    self.assertIn(record + "." + field, sql)
                function = sql[sql.index("CREATE OR REPLACE FUNCTION") :].strip()
                self.assertIn(
                    function.replace("CREATE OR REPLACE FUNCTION", "CREATE FUNCTION", 1), dump
                )
        # Existing memberships are updated; new memberships get the same contact update
        # after their legacy INSERT. No course columns are added to the unguarded SQL.
        sync = (ROOT / "triggers/master/sync_user.sql").read_text()
        self.assertIn("FROM courses_users WHERE user_id=NEW.user_id", sync)
        insert = (ROOT / "triggers/master/sync_courses_user.sql").read_text()
        self.assertLess(insert.index("IF (TG_OP = 'INSERT')"), insert.index("personal_webpage=%L"))
        self.assertLess(
            insert.index("personal_webpage=%L"), insert.index("ELSIF (TG_OP = 'UPDATE')")
        )

    def test_rollbacks_preserve_saved_values(self):
        MASTER.down(self.config, self.database)
        COURSE.down(self.config, self.database, "f26", "sample")
        self.database.execute.assert_not_called()

    def test_triggers_load_after_selected_environments_and_courses(self):
        with TemporaryDirectory() as directory:
            for course in ("active", "archived"):
                (Path(directory) / "courses" / "f26" / course).mkdir(parents=True)
            database = Mock()
            database.execute.return_value.mappings.return_value = [
                {"term": "f26", "course": "active"},
                {"term": "f26", "course": "archived"},
            ]

            cases = [
                (
                    ["master", "system", "course"],
                    None,
                    ["master", "system", "active", "archived", "triggers"],
                ),
                (["master"], None, ["master", "triggers"]),
                (["master", "course"], ["f26", "active"], ["master", "active", "triggers"]),
                (["course"], None, ["active", "archived", "triggers"]),
            ]
            for environments, choose_course, expected in cases:
                with self.subTest(environments=environments, choose_course=choose_course):
                    events = []
                    args = SimpleNamespace(
                        environments=environments,
                        choose_course=choose_course,
                        config=SimpleNamespace(
                            database={}, submitty={"submitty_data_dir": directory}
                        ),
                    )

                    def migrate(database, environment, args, missing):
                        events.append(environment if environment != "course" else args.course)

                    with (
                        patch.object(main.db, "Database", return_value=database),
                        patch.object(main, "migrate_environment", side_effect=migrate),
                        patch.object(
                            main, "load_triggers", side_effect=lambda *_: events.append("triggers")
                        ),
                    ):
                        main.handle_migration(args)
                    self.assertEqual(events, expected)
