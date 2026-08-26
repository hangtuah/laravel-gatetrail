<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Contracts;

/**
 * Marks every event GateTrail dispatches as part of its public contract.
 *
 * Laravel's event dispatcher resolves listeners registered against an event's
 * interfaces, so listening to this interface receives every GateTrail event.
 * It does not resolve listeners registered against a parent class, which is
 * why the package marks its events rather than sharing a base class.
 */
interface GateTrailEvent
{
    /**
     * The event-specific safe context exposed to generic listeners.
     *
     * @return array<string, mixed>
     */
    public function context(): array;
}
