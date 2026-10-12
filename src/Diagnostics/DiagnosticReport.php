<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Diagnostics;

use InvalidArgumentException;

/**
 * Immutable result of one best-effort, read-only diagnostic run.
 *
 * @internal
 */
final readonly class DiagnosticReport
{
    /** @var list<DiagnosticFinding> */
    public array $findings;

    /** @var array<string, bool> */
    public array $coverage;

    /**
     * @param  list<DiagnosticFinding>  $findings
     * @param  array<string, bool>  $coverage
     */
    public function __construct(array $findings, array $coverage)
    {
        if ($coverage === []) {
            throw new InvalidArgumentException(
                'GateTrail diagnostic reports must declare their coverage.',
            );
        }

        foreach ($coverage as $check => $complete) {
            if (
                preg_match('/^[a-z][a-z0-9_.-]*\z/', $check) !== 1
            ) {
                throw new InvalidArgumentException(
                    'GateTrail diagnostic coverage must map stable check identifiers to booleans.',
                );
            }
        }

        usort(
            $findings,
            static fn (DiagnosticFinding $left, DiagnosticFinding $right): int => [
                self::severityOrder($left->severity),
                $left->code,
                $left->reference ?? '',
            ] <=> [
                self::severityOrder($right->severity),
                $right->code,
                $right->reference ?? '',
            ],
        );

        ksort($coverage);

        $this->findings = $findings;
        $this->coverage = $coverage;
    }

    public function isComplete(): bool
    {
        return ! in_array(false, $this->coverage, true);
    }

    public function hasErrors(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === DiagnosticSeverity::Error) {
                return true;
            }
        }

        return false;
    }

    public function isClean(): bool
    {
        return $this->isComplete() && $this->findings === [];
    }

    public function status(): string
    {
        if (! $this->isComplete()) {
            return 'incomplete';
        }

        return $this->hasErrors() ? 'errors' : 'complete';
    }

    public function exitCode(): int
    {
        return $this->isComplete() && ! $this->hasErrors() ? 0 : 1;
    }

    /** @return array{error: int, warning: int, informational: int} */
    public function counts(): array
    {
        $counts = [
            'error' => 0,
            'warning' => 0,
            'informational' => 0,
        ];

        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    private static function severityOrder(DiagnosticSeverity $severity): int
    {
        return match ($severity) {
            DiagnosticSeverity::Error => 0,
            DiagnosticSeverity::Warning => 1,
            DiagnosticSeverity::Informational => 2,
        };
    }
}
