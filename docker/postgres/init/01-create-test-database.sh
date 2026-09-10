#!/bin/sh
#
# Creates the database the test suite runs against, once, when the data volume
# is first initialised.
#
# The suite runs against PostgreSQL rather than SQLite (see apps/api/phpunit.xml
# for why), and RefreshDatabase drops and recreates every table it finds. That
# has to happen somewhere other than the database somebody is developing
# against, which is the whole reason this file exists.
#
# Postgres runs everything in /docker-entrypoint-initdb.d on first start only.
# If you change this file, the volume has to go with it: `make reset`.
set -eu

psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
	CREATE DATABASE ${POSTGRES_DB}_test OWNER $POSTGRES_USER;
EOSQL

echo "Created ${POSTGRES_DB}_test for the test suite."
