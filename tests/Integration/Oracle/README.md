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

The Oracle suite covers DBA-managed release `0001` with both the `identity`
and `sequence_trigger` generated-key strategies. It proves artifact
installation, read-only inspection, generated and explicit IDs, transaction
rollback, non-repairing drift detection, and artifact rollback.

Each strategy runs against a clean schema. The safety cleanup removes both
configured sequence-trigger objects and Oracle-owned identity objects through
their owning tables, preventing one strategy from contaminating another.

Oracle Free 23 integration is current-version evidence. It does not by itself
prove GateTrail's declared Oracle 12c Release 2 minimum.
