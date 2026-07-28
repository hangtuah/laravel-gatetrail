<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests;

use HangTuah\GateTrail\GateTrailServiceProvider;
use PHPUnit\Framework\TestCase;

final class PackageMetadataTest extends TestCase
{
    public function test_composer_metadata_enables_laravel_auto_discovery(): void
    {
        $composer = json_decode(
            file_get_contents(__DIR__.'/../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame(
            [GateTrailServiceProvider::class],
            $composer['extra']['laravel']['providers'],
        );
    }
}
