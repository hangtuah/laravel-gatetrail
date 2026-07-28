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

The first Oracle lane covers DBA-managed release `0001` with the
`sequence_trigger` generated-key strategy. It proves artifact installation,
read-only inspection, generated and explicit IDs, transaction rollback,
non-repairing drift detection, and artifact rollback.

Oracle Free 23 integration is current-version evidence. It does not by itself
prove GateTrail's declared Oracle 12c Release 2 minimum.
