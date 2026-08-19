<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Support;

use HangTuah\GateTrail\Concerns\HasGateTrail;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Models\GateTrailModel;
use Illuminate\Database\Eloquent\Model;

/**
 * The one subject class every engine in the acceptance matrix authorizes.
 *
 * It is deliberately a single class rather than one fixture per engine,
 * because `subject_type` is a **persisted value**: two subject classes would
 * write two different morph strings, and "SQLite and MySQL produced the same
 * effective permissions" would then be comparing two different subjects. One
 * class means one morph class, one canonical identifier, and a parity claim
 * that means what it says.
 *
 * Its connection is resolved the way {@see GateTrailModel}
 * resolves one — through `SchemaContext` — so it lands on whichever connection
 * the test environment configured for GateTrail, whether that is SQLite in
 * memory, live MySQL or live Oracle, with no per-engine subclass.
 *
 * The table is short by design: Oracle's 30-character identifier bound applies
 * to test fixtures exactly as it applies to package tables.
 */
final class AcceptanceSubject extends Model
{
    use HasGateTrail;

    public const TABLE = 'gt_acceptance_subjects';

    public $timestamps = false;

    protected $table = self::TABLE;

    protected $guarded = [];

    protected string $gateTrailGuardName = 'web';

    public function getConnectionName(): ?string
    {
        return app(SchemaContext::class)->connectionName()
            ?? parent::getConnectionName();
    }
}
