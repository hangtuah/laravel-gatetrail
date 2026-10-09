<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Laravel;

use HangTuah\GateTrail\Exceptions\InvalidMiddlewareParameter;
use HangTuah\GateTrail\Laravel\Middleware\MiddlewareMatchMode;
use HangTuah\GateTrail\Laravel\Middleware\MiddlewareParameterNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MiddlewareParameterNormalizerTest extends TestCase
{
    private MiddlewareParameterNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new MiddlewareParameterNormalizer;
    }

    #[Test]
    public function it_trims_preserves_case_and_removes_exact_duplicates(): void
    {
        $this->assertSame(
            ['Documents.View', 'documents.view'],
            $this->normalizer->normalize(
                'gatetrail.permission.any',
                MiddlewareMatchMode::Any,
                [' Documents.View ', 'Documents.View', 'documents.view'],
            ),
        );
    }

    #[Test]
    public function singular_mode_requires_exactly_one_normalized_value(): void
    {
        $this->expectExceptionObject(
            InvalidMiddlewareParameter::invalidCardinality(
                'gatetrail.permission',
            ),
        );

        $this->normalizer->normalize(
            'gatetrail.permission',
            MiddlewareMatchMode::One,
            ['documents.view', 'documents.edit'],
        );
    }

    #[Test]
    public function exact_duplicates_do_not_break_singular_cardinality(): void
    {
        $this->assertSame(
            ['documents.view'],
            $this->normalizer->normalize(
                'gatetrail.permission',
                MiddlewareMatchMode::One,
                [' documents.view ', 'documents.view'],
            ),
        );
    }

    #[Test]
    public function any_and_all_require_at_least_one_value(): void
    {
        foreach ([MiddlewareMatchMode::Any, MiddlewareMatchMode::All] as $mode) {
            try {
                $this->normalizer->normalize(
                    'gatetrail.permission.any',
                    $mode,
                    [],
                );
                $this->fail('An empty parameter list was accepted.');
            } catch (InvalidMiddlewareParameter $exception) {
                $this->assertSame(
                    InvalidMiddlewareParameter::REASON_CARDINALITY,
                    $exception->reason,
                );
            }
        }
    }

    #[Test]
    public function an_empty_value_is_rejected_without_disclosing_other_names(): void
    {
        try {
            $this->normalizer->normalize(
                'gatetrail.permission.all',
                MiddlewareMatchMode::All,
                ['documents.secret', '  '],
            );
            $this->fail('An empty middleware parameter was accepted.');
        } catch (InvalidMiddlewareParameter $exception) {
            $this->assertSame(
                InvalidMiddlewareParameter::REASON_EMPTY,
                $exception->reason,
            );
            $this->assertStringNotContainsString(
                'documents.secret',
                $exception->getMessage(),
            );
        }
    }
}
