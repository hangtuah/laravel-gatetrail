# MySQL integration suite

This opt-in suite validates GateTrail against a real MySQL server. It is kept
separate from `composer check` so normal package development does not require
Docker.

## Local container

Start the disposable MySQL 8.0.45 service:

```bash
docker compose -f compose.mysql.yml up -d --wait
composer test:mysql
```

Stop and remove the service:

```bash
docker compose -f compose.mysql.yml down
```

The default connection is:

```text
Host: 127.0.0.1
Port: 33061
Database: gatetrail_test
Username: gatetrail
Password: gatetrail_test
```

Override these values with `GATETRAIL_MYSQL_HOST`,
`GATETRAIL_MYSQL_PORT`, `GATETRAIL_MYSQL_DATABASE`,
`GATETRAIL_MYSQL_USERNAME`, and `GATETRAIL_MYSQL_PASSWORD`.

## Safety boundary

The suite performs destructive schema setup and cleanup. It refuses:

- a host other than `127.0.0.1`, `localhost`, or the CI service name `mysql`;
- a database name that does not end with `_test`;
- an active database that differs from the configured database.

Cleanup is limited to the seven canonical GateTrail tables and Laravel's
`migrations` table. The suite never drops the database.

## Coverage

The suite verifies:

- package-managed migration, inspection, and rollback;
- DBA-managed release `0001` install, inspection, and rollback;
- MySQL `information_schema` normalization;
- missing-index detection without automatic repair;
- exact-name collation drift;
- cascading foreign-key drift.
