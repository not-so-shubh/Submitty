"""Add optional contact information and copy existing members' master profiles."""

from copy import deepcopy

from sqlalchemy import text

from migrator.db import Database


def up(config, database, semester, course):
    database.execute("""
        ALTER TABLE users
            ADD COLUMN IF NOT EXISTS linkedin_username character varying(100) NOT NULL DEFAULT '',
            ADD COLUMN IF NOT EXISTS github_username character varying(39) NOT NULL DEFAULT '',
            ADD COLUMN IF NOT EXISTS personal_webpage character varying(2048) NOT NULL DEFAULT '';
    """)

    master_config = deepcopy(config.database)
    master_config["dbname"] = "submitty"
    master = Database(master_config, "master")
    try:
        # A course-only migration can precede the master migration. In that case
        # there are no saved contact values to copy yet.
        if not all(
            master.table_has_column("users", field)
            for field in ("linkedin_username", "github_username", "personal_webpage")
        ):
            return
        users = master.execute(
            text("""
            SELECT u.user_id, u.linkedin_username, u.github_username, u.personal_webpage
            FROM users u JOIN courses_users cu ON cu.user_id = u.user_id
            WHERE cu.term = :term AND cu.course = :course
        """).bindparams(term=semester, course=course)
        ).mappings()
        for user in users:
            database.execute(
                text("""
                UPDATE users SET linkedin_username = :linkedin_username,
                    github_username = :github_username, personal_webpage = :personal_webpage
                WHERE user_id = :user_id
            """).bindparams(**dict(user))
            )
    finally:
        master.close()


def down(config, database, semester, course):
    # Preserve contact information on rollback, as with secondary email.
    pass
