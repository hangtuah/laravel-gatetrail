<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Diagnostics;

use LogicException;

/**
 * What one measured window executed, as counts and nothing else.
 *
 * Every property is an `int`. There is no SQL text, no binding, no duration,
 * no connection name, no subject or definition identifier, no guard, no model
 * and no collection — so an instance can be kept, printed, dumped or attached
 * to a failure message without disclosing anything about the data the measured
 * statements touched.
 *
 * The category vocabulary is **fixed and closed**. A caller cannot supply a
 * key, because a caller-supplied key is a caller-supplied string, and a
 * caller-supplied string is how SQL fragments and identifiers end up retained
 * in something that was only ever supposed to count.
 *
 * | Category | Meaning |
 * |---|---|
 * | `select` | A statement whose leading keyword is `SELECT` |
 * | `insert` / `update` / `delete` | The corresponding DML keyword |
 * | `schema` | `CREATE`, `ALTER`, `DROP`, `TRUNCATE`, `RENAME` |
 * | `other` | Anything else, including anything the classifier is unsure of |
 *
 * `other` is load-bearing rather than a dumping ground: the classifier is
 * deliberately conservative, so an unrecognized statement lands there instead
 * of being guessed into `select`. A resolver budget assertion of "3 statements,
 * 3 select" is therefore a real claim — an unexpected statement shape would
 * move the count into `other` and break it.
 *
 * `total` is derived, not supplied, so it cannot drift from the categories.
 *
 * @internal Test and diagnostic use only. Not consumer API.
 *
 * @see docs/decisions/0012-effective-access-resolution.md section 8
 */
final readonly class StatementMeasurement
{
    private function __construct(
        public int $total,
        public int $select,
        public int $insert,
        public int $update,
        public int $delete,
        public int $schema,
        public int $other,
    ) {}

    /**
     * @internal Package-controlled construction. Not supported consumer API.
     */
    public static function of(
        int $select,
        int $insert,
        int $update,
        int $delete,
        int $schema,
        int $other,
    ): self {
        foreach ([$select, $insert, $update, $delete, $schema, $other] as $count) {
            if ($count < 0) {
                // A negative count means the tally was corrupted, which is a
                // package defect rather than a measurement result. The message
                // names no statement and carries no value.
                throw new LogicException(
                    'A GateTrail statement count cannot be negative.',
                );
            }
        }

        return new self(
            $select + $insert + $update + $delete + $schema + $other,
            $select,
            $insert,
            $update,
            $delete,
            $schema,
            $other,
        );
    }

    /**
     * The zero measurement, which is what a warm memo hit must produce.
     *
     * @internal Package-controlled construction. Not supported consumer API.
     */
    public static function none(): self
    {
        return self::of(0, 0, 0, 0, 0, 0);
    }
}
