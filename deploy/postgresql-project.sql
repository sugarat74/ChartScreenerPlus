-- Per-project PostgreSQL role and database (one of each per project).
-- Run through deploy/postgresql-add-project.sh as the postgres superuser:
--   psql -v project=<name> -f postgresql-project.sql
-- The password is read from the PROJECT_DB_PASSWORD environment variable so it
-- never appears in a process list or in this repository. Idempotent: re-running
-- re-asserts the attributes and rotates the password.
\set ON_ERROR_STOP on
\getenv password PROJECT_DB_PASSWORD
SET password_encryption = 'scram-sha-256';

SELECT format('CREATE ROLE %I LOGIN', :'project')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'project') \gexec

SELECT format(
  'ALTER ROLE %I WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD %L',
  :'project', :'password'
) \gexec

-- Session settings live on the role, not in a client SET, so they hold on any
-- server connection PgBouncer hands out in transaction mode.
SELECT format('ALTER ROLE %I SET search_path = public', :'project') \gexec
SELECT format('ALTER ROLE %I SET timezone = %L', :'project', 'UTC') \gexec

SELECT format('CREATE DATABASE %I OWNER %I ENCODING %L TEMPLATE template0', :'project', :'project', 'UTF8')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'project') \gexec

-- Only the owner (and superusers) may connect; no project reaches another's
-- database, and nobody but superusers reaches the maintenance databases.
SELECT format('REVOKE ALL ON DATABASE %I FROM PUBLIC', :'project') \gexec
REVOKE CONNECT ON DATABASE postgres FROM PUBLIC;
REVOKE CONNECT ON DATABASE template1 FROM PUBLIC;

\connect :"project"
-- PostgreSQL 15+ default; asserted for older clusters. The owner keeps CREATE
-- through pg_database_owner so migrations work.
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
