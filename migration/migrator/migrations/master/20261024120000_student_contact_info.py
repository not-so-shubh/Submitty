"""Add optional contact information to master user profiles."""


def up(config, database):
    database.execute("""
        ALTER TABLE users
            ADD COLUMN IF NOT EXISTS linkedin_username character varying(100) NOT NULL DEFAULT '',
            ADD COLUMN IF NOT EXISTS github_username character varying(39) NOT NULL DEFAULT '',
            ADD COLUMN IF NOT EXISTS personal_webpage character varying(2048) NOT NULL DEFAULT '';
    """)


def down(config, database):
    # Preserve contact information on rollback, as with secondary email.
    pass
