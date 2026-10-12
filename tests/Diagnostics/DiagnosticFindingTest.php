<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Diagnostics;

use HangTuah\GateTrail\Diagnostics\DiagnosticFinding;
use HangTuah\GateTrail\Diagnostics\DiagnosticSeverity;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiagnosticFindingTest extends TestCase
{
    public function test_it_accepts_a_privacy_safe_finding(): void
    {
        $reference = hash('sha256', 'role:42');
        $finding = new DiagnosticFinding(
            'assignment.overlap',
            DiagnosticSeverity::Error,
            'Equivalent assignment periods overlap.',
            $reference,
        );

        $this->assertSame('assignment.overlap', $finding->code);
        $this->assertSame(DiagnosticSeverity::Error, $finding->severity);
        $this->assertSame($reference, $finding->reference);
    }

    #[DataProvider('invalidFindings')]
    public function test_it_rejects_unstable_or_unsafe_fields(
        string $code,
        string $message,
        ?string $reference,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new DiagnosticFinding(
            $code,
            DiagnosticSeverity::Warning,
            $message,
            $reference,
        );
    }

    /** @return iterable<string, array{string, string, string|null}> */
    public static function invalidFindings(): iterable
    {
        yield 'empty code' => ['', 'Message.', null];
        yield 'uppercase code' => ['Assignment.Overlap', 'Message.', null];
        yield 'whitespace in code' => ['assignment overlap', 'Message.', null];
        yield 'empty message' => ['assignment.overlap', '  ', null];
        yield 'raw identifier as reference' => ['assignment.overlap', 'Message.', '42'];
        yield 'uppercase digest' => [
            'assignment.overlap',
            'Message.',
            strtoupper(hash('sha256', 'role:42')),
        ];
    }
}
