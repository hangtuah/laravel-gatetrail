<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Concurrency;

use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintDefinition;
use HangTuah\GateTrail\Database\Schema\Manifest\ConstraintType;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaAdapter;
use HangTuah\GateTrail\Database\Schema\SchemaAdapterFactory;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\UnsupportedDatabaseDriver;
use Illuminate\Database\QueryException;

/**
 * Confirms that one specific exception is *the* assignment-lock coordination
 * `INSERT` race, and nothing else.
 *
 * # Positional precondition — read this before calling
 *
 * **This classifier assumes its caller has already established positional
 * eligibility.** It answers "does this failure carry the coordination row's
 * identity?", not "did this failure come from the coordination insert?". Only
 * the `catch` around the single coordination-row `INSERT` in
 * `AssignmentLockStore::acquire()` may ask, and it must catch exactly that one
 * statement — not the surrounding read, not the re-read, not the enclosing
 * attempt.
 *
 * Positional eligibility is the primary safety boundary of ADR 0011 and the
 * only condition that does not depend on driver text at all. Calling this from
 * a wider `catch` would hand a definition namespace collision, a consumer-table
 * violation or a trigger failure the chance to be confirmed as a race on
 * whatever a driver's message happens to say. Identity confirmation here is
 * doing confirmation work, not identification work.
 *
 * In this increment the precondition is a caller contract, tested as such.
 * `AssignmentLockStore` is deliberately **not** wired yet: the marker and the
 * retry orchestrator that consumes it must land together, so that no internal
 * control-flow exception can escape to a consumer in the meantime.
 *
 * # What is confirmed
 *
 * All applicable conditions must agree, and any one of them failing means "not
 * a coordination race":
 *
 * 1. the exact positional caller contract above;
 * 2. a supported canonical driver;
 * 3. the exact SQLSTATE and vendor-code shape for that driver;
 * 4. the exact constraint, table and column identity in the driver's own
 *    diagnostic text.
 *
 * Identity is read only from `errorInfo[2]`, never from the Laravel-formatted
 * `QueryException` message, which carries the executed SQL and its bindings.
 * Diagnostic text is never sufficient on its own, on any driver.
 *
 * Every expected name comes from the package's existing sources of truth —
 * {@see SchemaManifest} for the logical primary key and its columns,
 * {@see SchemaContext} for the configured prefix and schema, and
 * {@see SchemaAdapter} for the canonical driver and the physical primary-key
 * name. There is no second manifest here and no literal table name.
 *
 * This is not `UniqueConstraintViolationClassifier`, and it deliberately does
 * not import, extend or call it — a cross-reference here would be the first
 * step towards the merge this design forbids. That class answers whether a
 * *terminal* named definition constraint was violated, so its answer becomes
 * `DuplicateRole` or `DuplicatePermission`. This one answers whether the
 * coordination insert lost a *transient* race worth retrying. Merging "this is
 * permanent" and "this is retryable" into one class is the exact confusion
 * ADR 0011 exists to prevent.
 *
 * @internal
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final readonly class CoordinationRaceClassifier
{
    /**
     * The manifest and configuration key of the coordination table.
     */
    private const TABLE = 'assignment_locks';

    private const CATEGORY = 'coordination_race';

    /**
     * The Oracle error that means "unique constraint violated".
     */
    private const ORACLE_DUPLICATE = 'ORA-00001';

    public function __construct(
        private SchemaContext $schema,
        private SchemaManifest $manifest,
        private SchemaAdapterFactory $adapters,
    ) {}

    /**
     * Returns the classified failure, or null when this is not the confirmed
     * coordination insert race.
     *
     * On failure nothing is retained: no exception, no message, no SQL, no
     * binding, no identifier. On success the returned value carries only the
     * canonical driver, the `coordination_race` category, the canonical
     * uppercase SQLSTATE actually observed, and the nullable numeric vendor
     * code.
     *
     * @param  string  $driver  A connection driver name. Aliases are canonicalized
     *                          through the schema adapters, so `mariadb` resolves
     *                          to `mysql` and `oci8` to `oracle`.
     *
     * @throws UnsupportedDatabaseDriver When the driver is not one GateTrail
     *                                   supports. An unsupported driver is not
     *                                   guessed at.
     */
    public function classify(
        QueryException $exception,
        string $driver,
    ): ?ClassifiedConcurrencyFailure {
        $adapter = $this->adapters->forDriver($driver);
        $canonical = $adapter->driver();

        $sqlState = DriverDiagnostics::sqlState($exception);
        $text = DriverDiagnostics::text($exception);

        if ($sqlState === null || $text === null) {
            return null;
        }

        $vendorCode = DriverDiagnostics::vendorCode($exception);

        if (! $this->confirms($adapter, $canonical, $sqlState, $vendorCode, $text)) {
            return null;
        }

        return ClassifiedConcurrencyFailure::classified(
            $canonical,
            self::CATEGORY,
            $sqlState,
            $vendorCode,
        );
    }

    private function confirms(
        SchemaAdapter $adapter,
        string $driver,
        string $sqlState,
        ?int $vendorCode,
        string $text,
    ): bool {
        $constraint = $this->primaryKeyName($adapter);

        return match ($driver) {
            'pgsql' => $sqlState === '23505'
                && $constraint !== null
                && $this->mentionsIdentifier($text, $constraint),

            // Owner-approved portability clarification of 2026-08-12: PDO's
            // Oracle drivers disagree about whether ORA-00001 arrives as 23000
            // or as HY000, and this repository's recorded Oracle vectors use
            // HY000. Accepting both widens the SQLSTATE alternatives only —
            // HY000 is Oracle's catch-all and confirms nothing by itself, so
            // the vendor code, the bounded ORA-00001 token and the exact
            // constraint identity are all still required together.
            'oracle' => in_array($sqlState, ['23000', 'HY000'], true)
                && $vendorCode === 1
                && $constraint !== null
                && $this->mentionsOracleError($text, self::ORACLE_DUPLICATE)
                && $this->mentionsIdentifier($text, $constraint),

            // 2627 is the named PRIMARY KEY / UNIQUE constraint violation.
            // 2601 is a duplicate key in a unique *index*, which this table
            // does not have, and no repository evidence shows it can carry
            // this named primary key. It stays rejected until evidence exists.
            'sqlsrv' => $sqlState === '23000'
                && $vendorCode === 2627
                && $constraint !== null
                && $this->mentionsIdentifier($text, $constraint),

            'sqlite' => $sqlState === '23000'
                && $vendorCode === 19
                && $this->matchesSqliteColumns($text),

            'mysql' => $sqlState === '23000'
                && $vendorCode === 1062
                && $constraint !== null
                && $this->matchesMySqlPrimaryKey($text, $constraint),

            default => false,
        };
    }

    /**
     * MySQL cannot name a primary key: every table's is `PRIMARY`, so the
     * token alone is not unique to GateTrail's lock table.
     *
     * MySQL 8.0.19 and later qualify it as `` `table`.`PRIMARY` ``, which
     * restores uniqueness, so when the diagnostic reports any qualified
     * primary key it must be this table's. Older servers report a bare
     * `PRIMARY` with no qualifier at all; that is accepted only because the
     * positional precondition has already reduced the candidate set to one
     * statement against one table whose only unique constraint is that
     * primary key.
     *
     * No rule here reads prose. "Duplicate entry" is absent on purpose.
     */
    private function matchesMySqlPrimaryKey(
        string $text,
        string $primaryKey,
    ): bool {
        $owners = $this->qualifiedPrimaryKeyOwners($text, $primaryKey);

        if ($owners !== []) {
            $table = $this->schema->tableName(self::TABLE);

            foreach ($owners as $owner) {
                if (strcasecmp($owner, $table) === 0) {
                    return true;
                }
            }

            // A qualified primary key that belongs to another table is a
            // positive statement that this is not the lock table's.
            return false;
        }

        return $this->mentionsIdentifier($text, $primaryKey);
    }

    /**
     * Every table qualifier reported immediately before the primary-key token,
     * with backtick and double-quote forms unwrapped.
     *
     * @return list<string>
     */
    private function qualifiedPrimaryKeyOwners(
        string $text,
        string $primaryKey,
    ): array {
        $quoted = preg_quote($primaryKey, '/');
        $pattern = sprintf(
            '/(?<![A-Za-z0-9_$#])'
            .'(?:`(?<backticked>[^`]+)`|"(?<quoted>[^"]+)"|(?<bare>[A-Za-z0-9_$#]+))'
            .'\s*\.\s*'
            .'(?:`%1$s`|"%1$s"|%1$s)'
            .'(?![A-Za-z0-9_$#])/i',
            $quoted,
        );

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $owners = [];

        foreach ($matches as $match) {
            foreach (['backticked', 'quoted', 'bare'] as $form) {
                $owner = $match[$form] ?? '';

                if ($owner !== '') {
                    $owners[] = $owner;

                    break;
                }
            }
        }

        return $owners;
    }

    /**
     * SQLite reports the violated columns rather than a constraint name, so
     * the identity is the exact reported column set.
     *
     * It reports `table.column` with no schema prefix, so the comparison uses
     * the resolved unqualified table name even when a schema is configured.
     * The set must match exactly: a missing column, an extra column, a
     * duplicate, another table or another column is not this race.
     *
     * `UNIQUE constraint failed:` is the one phrase this class reads, and it
     * is read as a discriminator rather than as identity: SQLite reuses code
     * 19 for NOT NULL and CHECK failures, which must not classify, and SQLite
     * emits no localized error text.
     */
    private function matchesSqliteColumns(string $text): bool
    {
        $token = '[A-Za-z0-9_$#."`\[\]]+';
        $pattern = sprintf(
            '/UNIQUE constraint failed:\s*(%1$s(?:\s*,\s*%1$s)*)/i',
            $token,
        );

        if (preg_match($pattern, $text, $matches) !== 1) {
            return false;
        }

        $table = $this->schema->tableName(self::TABLE);
        $columns = $this->primaryKeyColumns();

        if ($columns === []) {
            return false;
        }

        $reported = array_map(
            static fn (string $reference): string => strtolower(
                trim($reference, " \t\"`[]'"),
            ),
            explode(',', $matches[1]),
        );
        $expected = array_map(
            static fn (string $column): string => strtolower(
                $table.'.'.$column,
            ),
            $columns,
        );

        sort($reported);
        sort($expected);

        return $reported === $expected;
    }

    /**
     * Matches one identifier as a whole token.
     *
     * The match is bounded by characters that cannot appear in an identifier,
     * so `acl_asgn_locks_pk` never matches `acl_asgn_locks_pk_2`,
     * `x_acl_asgn_locks_pk` or `acl_asgn_locks_pkey`. Because every accepted
     * quoting form — double quotes, backticks, SQL Server brackets, single
     * quotes, and Oracle's parenthesised schema qualification — is built from
     * boundary characters, each is accepted without a rule of its own, and a
     * schema- or table-qualified name matches through its separating dot.
     *
     * Matching is case-insensitive. Oracle folds identifiers to upper case,
     * PostgreSQL to lower case, and SQL Server and MySQL compare them
     * case-insensitively under their default collations, so a case-sensitive
     * match would reject real races. Surrounding prose is irrelevant either
     * way: only the token decides.
     */
    private function mentionsIdentifier(string $text, string $identifier): bool
    {
        $pattern = sprintf(
            '/(?<![A-Za-z0-9_$#])%s(?![A-Za-z0-9_$#])/i',
            preg_quote($identifier, '/'),
        );

        return preg_match($pattern, $text) === 1;
    }

    /**
     * Matches one Oracle error code as a bounded token, so `ORA-00001` never
     * matches `ORA-000012` and never matches inside a longer word.
     */
    private function mentionsOracleError(string $text, string $code): bool
    {
        $pattern = sprintf(
            '/(?<![A-Za-z0-9_$#])%s(?![A-Za-z0-9_$#])/i',
            preg_quote($code, '/'),
        );

        return preg_match($pattern, $text) === 1;
    }

    /**
     * The physical primary-key name of the lock table on this engine, resolved
     * from the manifest's logical name through the schema adapter.
     *
     * Null means the engine cannot name a primary key at all, which is SQLite,
     * where identity is the reported column set instead.
     */
    private function primaryKeyName(SchemaAdapter $adapter): ?string
    {
        $logical = $this->primaryKey()?->name;

        if ($logical === null) {
            return null;
        }

        return $adapter->physicalPrimaryKeyName($logical);
    }

    /**
     * @return list<string>
     */
    private function primaryKeyColumns(): array
    {
        $primaryKey = $this->primaryKey();

        return $primaryKey === null ? [] : $primaryKey->columns;
    }

    /**
     * The lock table's primary key, from the one schema manifest.
     *
     * A null answer means the manifest no longer declares one, which is a
     * package defect rather than a race. Classification then fails closed and
     * the original exception propagates unchanged, exactly as any other
     * unconfirmed failure does.
     */
    private function primaryKey(): ?ConstraintDefinition
    {
        foreach ($this->manifest->table(self::TABLE)->constraints as $constraint) {
            if ($constraint->type === ConstraintType::Primary) {
                return $constraint;
            }
        }

        return null;
    }
}
