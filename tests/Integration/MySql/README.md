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

Override the image to exercise the minimum supported version:

```bash
GATETRAIL_MYSQL_VERSION=8.0.16 \
docker compose -f compose.mysql.yml up -d --force-recreate --wait

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

## Parallel coordination suite

`ParallelCoordinationTest` is the only test in this package that produces real
concurrency. It spawns separate PHP processes, each opening its own MySQL
connection, releases them together from a database barrier, and lets the server
decide which writer loses the coordination-row race.

It is deliberately different from every other concurrency test here:

- no mocked classifier, injected exception or simulated race;
- contention is real InnoDB primary-key contention on `acl_assignment_locks`;
- workers call the public `AssignmentManager` method, not an internal seam;
- the barrier is bounded polling against a hard deadline, not a fixed sleep;
- child processes are terminated and the two helper tables dropped in
  `tearDown()`, including when the test fails.

The invariant it asserts is ADR 0001's: whatever the interleaving, the scope
ends with exactly one active assignment, one coordination row carrying the
canonical key, and one audit row. A classified retryable failure must never
reach the caller as a raw duplicate-key, deadlock or serialization exception.

ADR 0011 treats a passing run of this suite on **both** supported MySQL
versions as the acceptance evidence for bounded concurrency retry. Until that is
observed, `ROADMAP.md` keeps the corresponding item unchecked.
