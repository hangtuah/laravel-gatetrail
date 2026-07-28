# Oracle integration tests

This suite validates GateTrail against a disposable Oracle Free endpoint without
adding Laravel-OCI8 or OCI8 to the package's normal development dependencies.

## Requirements

- Docker
- PHP 8.3 with OCI8 3.0.1 or later
- Composer

Install the isolated integration runtime:

```bash
composer install --working-dir=tools/oracle-integration
```

The committed lock file is the current Laravel 13 reference lane. The
integration manifest also permits the matching Laravel 12 combination:

```text
Laravel 12 + Testbench 10 + Laravel-OCI8 12
Laravel 13 + Testbench 11 + Laravel-OCI8 13
```

Resolve compatibility lanes in a clean checkout so the committed reference
lock is not rewritten. Do not mix Laravel, Testbench, and Laravel-OCI8 majors.

Start Oracle and wait for it to become healthy:

```bash
docker compose -f compose.oracle.yml up -d --wait
```

Run the integration suite:

```bash
composer test:oracle
```

Remove the disposable endpoint:

```bash
docker compose -f compose.oracle.yml down
```

The default endpoint is deliberately restricted to `127.0.0.1:15211`, service
`FREEPDB1`, and application schema `GATETRAIL`. The test guard rejects another
host, service, schema, or privileged `SYS`/`SYSTEM` account.

The Oracle suite covers both `package` and `dba` provisioning with the
`identity` and `sequence_trigger` generated-key strategies. It proves migration
and artifact installation, read-only inspection, generated and explicit IDs,
transaction rollback, non-repairing drift detection, migration rollback, and
artifact rollback.

Package-managed Oracle keeps Laravel migration tracking while each GateTrail
migration executes a one-table slice of the same canonical Oracle compiler used
to generate DBA artifacts. Other database drivers retain their existing
Laravel Schema Builder path.

Each strategy runs against a clean schema. The safety cleanup removes both
configured sequence-trigger objects and Oracle-owned identity objects through
their owning tables, preventing one strategy from contaminating another.

Oracle Free 23 integration is current-version evidence. It does not by itself
prove GateTrail's declared Oracle 12c Release 2 minimum.

## GitHub Actions policy

The `Oracle compatibility` workflow runs both supported Laravel/OCI8 major
lanes sequentially against one Oracle service container. This avoids pulling
and starting the Oracle image once per major.

The workflow runs manually and on `main` pushes that change Oracle integration,
schema, migration, or dependency inputs. It has no scheduled trigger. Repeated
runs for the same Git reference cancel an older in-progress run.

The Laravel 13 lane installs the committed reference lock. The Laravel 12 lane
uses a temporary clean checkout and resolves its matching dependencies without
rewriting the reference workspace.
