# GateTrail PostgreSQL DBA artifacts

These artifacts provision GateTrail when database objects are managed outside
the application deployment account.

## Compatibility

- PostgreSQL 14 or later
- the built-in deterministic `C` collation

Release `0001` contains:

- `0001/install.sql` — creates the seven GateTrail tables and fifteen indexes
  in parent-before-child dependency order;
- `0001/rollback.sql` — drops the seven tables in child-before-parent order.

The committed artifacts use GateTrail's canonical table names without a schema
qualifier or custom prefix. Set the PostgreSQL `search_path` to the intended
schema before running the script. Environments that configure an explicit
schema, prefix or table name must generate and review matching DDL from the
same canonical manifest.

## Installation procedure

1. Confirm that the target database, schema and PostgreSQL version are correct.
2. Confirm that the `C` collation exists and none of the target objects exists.
3. Take the backup or recovery action required by the organization.
4. Stop application writes that could race with provisioning.
5. Run `0001/install.sql` as the database owner.
6. Confirm that every statement completed successfully.
7. Configure GateTrail with `database.provisioning` set to `dba`.
8. Run GateTrail's read-only PostgreSQL schema inspection when that adapter is
   available; until then, compare the installed catalog with the manifest as
   part of the DBA change review.

The install script intentionally has no `IF NOT EXISTS` clauses. An existing
object is a conflict requiring investigation, not a successful no-op.

PostgreSQL supports transactional DDL. Run the complete install in one explicit
transaction when the organization's deployment policy permits it, and roll the
transaction back if any statement fails.

## Runtime privileges

After installation, the application runtime account does not need `CREATE`,
`ALTER` or `DROP` privileges for GateTrail objects. Grant only the table and
identity-sequence privileges required by the application.

## Rollback procedure

`0001/rollback.sql` permanently drops GateTrail tables and their stored data.
Use it only as an explicitly approved removal or failed-installation recovery
step:

1. stop application traffic;
2. take and verify the required backup;
3. confirm that release `0001` is the installed schema;
4. run the rollback using the database-owner account;
5. verify all seven objects were removed.

The rollback script intentionally has no `IF EXISTS` or `CASCADE` clauses. A
missing object or unexpected dependency is reported instead of hidden.

## Reproducibility

The SQL files are generated from GateTrail's canonical schema manifest:

```bash
composer ddl:generate
```

Do not edit generated SQL manually. Automated tests compare the committed files
byte-for-byte with compiler output and fail when an artifact is stale.
