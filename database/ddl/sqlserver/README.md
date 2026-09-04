# GateTrail SQL Server DBA artifacts

These artifacts provision GateTrail when database objects are managed outside
the application deployment account.

## Compatibility

- SQL Server 2017 or later
- Unicode `NVARCHAR`/`NCHAR` character storage
- the `Latin1_General_100_BIN2` collation for exact-comparison columns

Release `0001` contains:

- `0001/install.sql` — creates the seven GateTrail tables in
  parent-before-child dependency order, followed by the fifteen explicit
  indexes;
- `0001/rollback.sql` — drops the same seven tables in child-before-parent
  order.

A correctly installed release `0001` schema therefore has **seven tables** and
**fifteen explicit indexes**, plus the indexes SQL Server creates on its own to
back the seven primary keys and two unique constraints.

The committed artifacts use GateTrail's canonical table names with **no schema
qualifier and no prefix**, so every object is created in the default schema of
the principal running the script — normally `dbo`. Select the target database
before running the file (for example with the client's database selector or a
`USE` statement you supply yourself; the artifact deliberately contains
neither). To install into a schema other than the executing principal's
default, or to use a configured prefix or custom table names, generate and
review matching DDL from the same canonical manifest rather than editing these
files.

## Schema decisions

| Manifest type | SQL Server type | Why |
|---|---|---|
| `BigInteger` | `BIGINT` | SQL Server has no unsigned integer type, so identifiers are signed here as they are on PostgreSQL and Oracle |
| `DateTime` | `DATETIME2(0)` | Whole-second precision matching the other drivers; avoids `DATETIME`'s 1753 floor and 3.33 ms rounding |
| `FixedString` | `NCHAR(n)` | Fixed-width Unicode |
| `String` | `NVARCHAR(n)` | Bounded Unicode |
| `Text` | `NVARCHAR(MAX)` | Supported large-object Unicode type; the deprecated `NTEXT` is never emitted |

**Generated keys.** Every manifest-generated identifier is
`BIGINT IDENTITY(1,1) NOT NULL`, SQL Server's native mechanism and the one
Eloquent relies on when it omits the key from an insert. Six of the seven
tables carry one; `acl_assignment_locks` is keyed by its scope instead. Bulk
loading rows with explicit identifiers requires
`SET IDENTITY_INSERT <table> ON` for the duration of the load, which is a
data-migration concern rather than a runtime one.

**Collation.** Columns the manifest marks for exact comparison are pinned to
`Latin1_General_100_BIN2` in the DDL rather than left to the database default,
because a SQL Server database is commonly created case-insensitive (for example
`SQL_Latin1_General_CP1_CI_AS`). Without the explicit collation, two role names
differing only in case would collide in `acl_role_name_guard_uq`. Character
columns that are **not** exact-comparison — `ip_address`, `request_id`,
`user_agent`, `scope_key` and the free-text columns — deliberately inherit the
database default so ordinary text keeps the deployment's own linguistic
comparison.

**Foreign keys.** All four use `ON DELETE NO ACTION ON UPDATE NO ACTION`. SQL
Server has no `RESTRICT` keyword; `NO ACTION` is its restrictive form and is
what the other drivers' `RESTRICT` means. No cascading, `SET NULL` or
`SET DEFAULT` action is emitted anywhere, because each would destroy assignment
history GateTrail promises to keep.

**Index key widths.** Unicode doubles the byte width of an indexed character
column. The widest key this schema creates is `acl_audit_actor_ix` at 770
bytes, inside SQL Server's 1700-byte nonclustered limit, and the only
character-keyed primary key, `acl_asgn_locks_pk`, is 192 bytes, inside the
900-byte clustered limit.

**Identifiers.** Every table, index and constraint name is at or below
GateTrail's 30-character portability standard.

## Installation procedure

1. Confirm that the target database, the executing principal's default schema
   and the SQL Server version are correct.
2. Confirm that none of the seven target tables already exists.
3. Take the backup or recovery action required by the organization.
4. Stop application writes that could race with provisioning, and schedule the
   downtime the organization's change policy requires.
5. Run `0001/install.sql` using the organization's database-owner account.
6. Confirm that every statement completed successfully.
7. Configure GateTrail with `database.provisioning` set to `dba`.

The install script intentionally has no `IF NOT EXISTS` clauses. An existing
object is a conflict requiring investigation, not a successful no-op.

The artifacts contain no `GO` batch separator, so they execute as ordinary
semicolon-terminated statements through any client — including ones that do not
implement the SQLCMD batch parser.

SQL Server supports transactional DDL, so `CREATE TABLE` and `CREATE INDEX` can
be rolled back inside an explicit transaction. Wrapping the whole install in one
transaction is therefore possible where the organization's deployment policy
permits it, but hold it only as long as necessary: the schema-modification locks
it takes are held until commit.

## Rollback procedure

`0001/rollback.sql` permanently drops GateTrail tables and every row in them.
Use it only as an explicitly approved removal or failed-installation recovery
step:

1. stop application traffic;
2. take and verify the required backup;
3. confirm that release `0001` is the installed schema;
4. run the rollback using the database-owner account;
5. verify all seven tables were removed.

The rollback script intentionally has no `IF EXISTS` clause and drops the tables
child-before-parent, so the foreign keys are removed with the tables that own
them and no cascading behaviour is needed. A missing object or unexpected
dependency is reported instead of hidden.

## Runtime privileges

After installation, the application runtime account does not need `CREATE`,
`ALTER` or `DROP` privileges for GateTrail objects. Grant only the table-level
`SELECT`, `INSERT`, `UPDATE` and `DELETE` the application actually uses.

## Reproducibility

The SQL files are generated from GateTrail's canonical schema manifest:

```bash
composer ddl:generate
```

Do not edit generated SQL manually. Automated tests compare the committed files
byte-for-byte with compiler output and fail when an artifact is stale.

## Current evidence boundary

Read this before treating SQL Server as a supported runtime.

**What has been verified.** The release `0001` install and rollback artifacts
have been executed against a live disposable SQL Server 2022 database whose
default collation was case-insensitive. That run confirmed the seven tables,
fifteen explicit indexes, seven primary keys, two unique constraints, four
foreign keys and seven check constraints; that every foreign key is `NO ACTION`
and every foreign-key and check constraint is enabled and trusted; that all
timestamp columns are `DATETIME2` with scale 0; that every character column is
Unicode and only the exact-comparison ones carry `Latin1_General_100_BIN2`; that
identity generation returns a server-generated key; that case-sensitive
comparison holds despite the case-insensitive database default; and that
rollback removes every object. This is **schema-level artifact evidence**.

**What has not been verified.** No GateTrail mutation, resolver, audit or
concurrency path has run against SQL Server. Application runtime compatibility
is not claimed.

**There is no SQL Server schema inspector yet.** GateTrail's read-only
inspection is available for MySQL, Oracle and PostgreSQL;
`SchemaInspectorFactory` still refuses the `sqlsrv` driver. Until it exists,
compare the installed catalog with the manifest as part of the DBA change
review — `sys.tables`, `sys.indexes`, `sys.key_constraints`,
`sys.foreign_keys`, `sys.check_constraints`, `sys.identity_columns` and
`sys.columns` carry everything the comparison needs.
