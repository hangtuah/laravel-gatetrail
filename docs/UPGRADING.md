# Upgrading GateTrail

This guide defines the safe upgrade workflow for GateTrail consumers. It
covers both package-managed migrations and DBA-managed schemas.

GateTrail is currently **pre-alpha and pre-`1.0.0`**, and no release has been
tagged. Schema release `0001` is the initial installation baseline, not an
upgrade. There is therefore no release-to-release SQL upgrade artifact yet.
When the first schema upgrade is introduced, its release notes must identify
the exact migration and DBA artifacts to use.

For a new installation, follow the
[consumer guide](CONSUMER_GUIDE.md) and the engine-specific DBA guide instead.
Do not apply `0001/install.sql` over an existing GateTrail schema.
`gatetrail:install` is also a fresh-install helper: it does not discover,
generate or execute a release-to-release upgrade path.

## 1. Compatibility policy before 1.0

The supported Tier 1 and Tier 2 surface is listed in
[`PUBLIC_API.md`](PUBLIC_API.md). Before `1.0.0`, that surface may change, but a
deliberate breaking change requires all of the following:

1. an ADR explaining the change;
2. a changelog entry;
3. migration guidance when consumer code or configuration must change.

Accidental drift is a defect. Classes marked `@internal` are not covered by
this policy and may change in any release, including a patch.

Until stable releases exist, use an exact pre-release constraint or another
deliberately controlled Composer constraint. Upgrade in a reviewable branch;
do not allow an unattended broad constraint to move a production application
between pre-release versions.

## 2. Identify the provisioning mode

The upgrade owner and procedure depend on `gatetrail.database.provisioning`:

| Mode | Schema owner | Upgrade mechanism |
|---|---|---|
| `package` | Application deployment | Versioned Laravel migrations shipped by GateTrail |
| `dba` | Database owner or DBA | Versioned, engine-specific SQL artifacts reviewed and applied outside the application account |

Do not switch modes merely to perform an upgrade. A mode change is an
operational migration that must be planned and verified independently.

## 3. Prepare the upgrade

Before changing the package version:

1. Read `CHANGELOG.md`, every release-specific upgrade note, and any ADR named
   by those notes.
2. Record the installed package version or commit, target version, Laravel and
   PHP versions, database driver and version, and provisioning mode.
3. Record GateTrail's configured connection, schema, table prefix, table
   names, custom models, guards and audit mode. For Oracle, also record the
   generated-key strategy actually installed.
4. Compare the application's published `config/gatetrail.php` with the target
   package's configuration file. Never include credentials or secret values in
   an upgrade report.
5. Take a database backup or snapshot and prove the organization's restore
   procedure works.
6. Rehearse the upgrade against a representative copy or staging environment.
7. Define the maintenance window, application deployment order, worker restart
   plan, verification owner and rollback decision point.
8. Quiesce GateTrail mutations when the release notes or database operation
   require it. This includes web traffic, queue workers and scheduled jobs that
   can write roles, permissions, assignments or audit records.

If the target release provides no upgrade path from the installed version,
stop. Do not infer one from the initial-installation artifacts.

## 4. Reconcile published configuration

Laravel does not merge newly introduced nested keys into an already published
application config file. Publishing again is not a safe upgrade procedure: it
may overwrite local decisions or leave the file unchanged.

For every upgrade:

1. diff the application's `config/gatetrail.php` against the target package's
   `config/gatetrail.php`;
2. add or change keys explicitly after reviewing their type, default and
   deployment effect in [`CONFIGURATION.md`](CONFIGURATION.md);
3. preserve environment-specific connection and schema choices deliberately;
4. validate custom model requirements before serving traffic;
5. rebuild Laravel's configuration cache after deployment when the application
   uses cached configuration.

Changing a table name, prefix, schema, guard namespace, model key strategy or
Oracle generated-key strategy is not a harmless alias change. Treat it as a
data/schema migration and require explicit release guidance.

## 5. Package-managed upgrade

Use this workflow when `gatetrail.database.provisioning` is `package`:

1. Update only the intended dependency in a reviewable branch, for example:

   ```bash
   composer update hangtuah/laravel-gatetrail --with-dependencies
   ```

2. Review the Composer diff, changelog, new GateTrail migration files and
   configuration diff.
3. Run the consuming application's tests and rehearse the database migration
   on representative data.
4. Take the approved backup and quiesce writers as required.
5. Deploy the compatible application code and configuration in the order
   stated by the release notes.
6. Apply reviewed migrations in the deployment environment:

   ```bash
   php artisan migrate --force
   ```

7. Perform the post-upgrade checks in section 9 before restoring traffic.

Never use `migrate:fresh`, `db:wipe` or an equivalent destructive command as an
upgrade step. Do not use a generic `migrate:rollback` blindly: it can roll back
unrelated application migrations, and a schema downgrade can destroy data.
Follow the release-specific rollback plan instead.

## 6. DBA-managed upgrade

Use this workflow when `gatetrail.database.provisioning` is `dba`:

1. Keep the application runtime account free of schema-changing privileges.
2. Obtain the exact target release's upgrade artifact for the installed source
   version and database engine. An install artifact is not an upgrade artifact.
3. Generate configuration-matching artifacts when the deployment uses a custom
   schema, prefix, table name or Oracle object name. Do not hand-edit a
   committed canonical artifact and continue to describe it as reproducible.
4. Have the database owner review the SQL, lock/commit behavior, execution
   order, estimated duration, backup, verification and rollback plan.
5. Rehearse against representative data, then quiesce writers as required.
6. Apply the artifact using the approved database-owner account.
7. Verify the resulting catalog before deploying or enabling application code
   that depends on it.
8. Deploy compatible application code and configuration, restart long-running
   workers, and perform section 9's checks.

The repository currently contains only release `0001` `install.sql` and
`rollback.sql` files. It contains no `0001`-to-later upgrade script. Every
`0001/rollback.sql` permanently drops GateTrail objects and their data; it is
for approved removal or failed-installation recovery, never a version
downgrade.

Engine-specific initial-installation constraints remain documented beside the
artifacts:

- [MySQL](../database/ddl/mysql/README.md)
- [Oracle](../database/ddl/oracle/README.md)
- [PostgreSQL](../database/ddl/postgresql/README.md)
- [SQL Server](../database/ddl/sqlserver/README.md)

## 7. Oracle generated-key strategy

An Oracle installation uses either identity columns or the sequence-trigger
strategy. Configuration must match the objects already installed.

Do not change `gatetrail.database.oracle.generated_keys.strategy` as part of a
routine package update. Moving between strategies changes database objects and
requires an explicit, DBA-reviewed schema and data migration supplied or
described by the relevant release. Never combine both strategies in one
GateTrail schema.

## 8. Rollback and recovery

Plan three separate recovery questions:

| Layer | Question |
|---|---|
| Application | Can the previous code and configuration run against the upgraded schema? |
| Schema | Is a reverse migration supplied, safe and tested for this release? |
| Data | Does reversal require restoring a coordinated database backup? |

The safest default after an irreversible or data-transforming migration is a
forward fix, not an improvised downgrade. If the previous application is not
compatible with the new schema, restoring code alone is not a rollback. Restore
the coordinated application, configuration and database recovery point, or
follow the release's documented forward-recovery procedure.

Never use a release `0001` rollback script to undo a later package version.

## 9. Post-upgrade verification

Before restoring normal traffic:

- confirm every intended migration or DBA statement completed and no
  unexpected GateTrail object changed;
- verify configuration resolves the intended connection, schema, tables,
  models, guard and audit mode;
- run the target release's documented schema verification procedure;
- exercise a controlled public-API mutation and confirm its
  `MutationResult`, persisted state and audit outcome;
- verify effective role and permission checks through the public resolvers or
  the consuming application's Gate/middleware path;
- verify scheduled and expired assignments still follow the half-open period
  rule;
- confirm queue workers and other long-running processes were restarted with
  the new code and configuration;
- monitor application, database and audit-failure reporting during the agreed
  observation window.

Use synthetic or approved test identities and clean up only through supported
mutation APIs. Do not edit assignment tables directly to manufacture a smoke
test.

## 10. Requirements for future schema releases

A GateTrail release that changes the schema is not ready until it provides:

- supported source and target versions;
- package-managed migration files in dependency-safe order;
- DBA-managed upgrade artifacts for every claimed Tier 1 database, or an
  explicit statement that an engine is not supported for that release;
- configuration and custom-model changes;
- deployment ordering and write-quiescence requirements;
- catalog and behavioral verification steps;
- rollback or forward-recovery guidance, including any irreversible step;
- automated evidence that fresh installation and the supported upgrade path
  both reach the canonical schema.

Release notes must name the exact artifact paths. Consumers must not guess an
upgrade path from filenames or generate an ad hoc migration from schema diffs.
