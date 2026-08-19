-- MySQL/MariaDB client entry point for the resumable v1.1.0 migration.
-- Run from the package root so these SOURCE paths resolve. Tools that do not
-- implement the mysql client SOURCE command must run the three files directly.
-- Invoke the client with --abort-source-on-error so a failed phase is visible
-- to deployment automation.

SOURCE sql/migrations/1.1.0-phase1.sql;
SOURCE sql/migrations/1.1.0-phase2.sql;
SOURCE sql/migrations/1.1.0-phase3.sql;
