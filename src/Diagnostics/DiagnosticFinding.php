<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Diagnostics;

use InvalidArgumentException;

/**
 * One privacy-safe observation produced by a GateTrail diagnostic check.
 *
 * @internal
 */
final readonly class DiagnosticFinding
{
    public function __construct(
        public string $code,
        public DiagnosticSeverity $severity,
        public string $message,
        public ?string $reference = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]*\z/', $this->code) !== 1) {
            throw new InvalidArgumentException(
                'GateTrail diagnostic finding codes must be stable lowercase identifiers.',
            );
        }

        if (trim($this->message) === '') {
            throw new InvalidArgumentException(
                'GateTrail diagnostic finding messages cannot be empty.',
            );
        }

        if (
            $this->reference !== null
            && preg_match('/^[a-f0-9]{64}\z/', $this->reference) !== 1
        ) {
            throw new InvalidArgumentException(
                'GateTrail diagnostic references must be SHA-256 digests.',
            );
        }
    }
}
