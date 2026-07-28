# GateTrail MySQL DBA artifacts

These artifacts provision GateTrail when database objects are managed outside
the application deployment account.

## Compatibility

- MySQL 8.0.16 or later
- InnoDB
- `utf8mb4`

Release `0001` contains:

- `0001/install.sql` — creates the seven GateTrail tables in parent-before-child
  dependency order;
- `0001/rollback.sql` — drops the same tables in child-before-parent order.

The committed artifacts use GateTrail's canonical table names without a
database qualifier or custom prefix. Select the target database before running
the script. Environments that configure another schema, prefix, or table name
must generate and review matching DDL from the same canonical manifest.

## Installation procedure

1. Confirm that the target database and MySQL version are correct.
2. Confirm that none of the seven target tables already exists.
3. Take the backup or recovery action required by the organization.
4. Stop application writes that could race with provisioning.
5. Run `0001/install.sql` using the organization's database-owner account.
6. Confirm that every statement completed successfully.
7. Configure GateTrail with `database.provisioning` set to `dba`.
8. Run GateTrail's read-only schema inspection before enabling application
   traffic.

The install script intentionally has no `IF NOT EXISTS` clauses. An existing
object is a conflict that requires investigation rather than something the
installer should silently accept.

MySQL performs implicit commits around DDL. Do not assume that wrapping the
script in a transaction makes the complete installation atomic.

## Runtime privileges

After installation, the application runtime account does not need `CREATE`,
`ALTER`, or `DROP` privileges for GateTrail objects. Grant only the data-access
privileges required by the application.

## Rollback procedure

`0001/rollback.sql` permanently drops GateTrail tables and their stored data.
Use it only as an explicitly approved removal or failed-installation recovery
step:

1. stop application traffic;
2. take and verify the required backup;
3. confirm that release `0001` is the installed schema;
4. run the rollback using the database-owner account;
5. verify all seven objects were removed.

The rollback script intentionally has no `IF EXISTS` clauses so an incomplete
or unexpected schema state is reported instead of hidden.

## Reproducibility

The SQL files are generated from GateTrail's canonical schema manifest:

```bash
composer ddl:generate
```

Do not edit the generated SQL manually. Automated tests compare the committed
files byte-for-byte with compiler output and fail when an artifact is stale.
