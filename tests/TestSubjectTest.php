<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests;

use HangTuah\GateTrail\Tests\Fixtures\TestSubject;

final class TestSubjectTest extends TestCase
{
    public function test_reusable_subject_uses_the_isolated_database(): void
    {
        $subject = TestSubject::query()->create([
            'name' => 'Test User',
        ]);

        $this->assertTrue($subject->exists);
        $this->assertSame(
            'Test User',
            TestSubject::query()->findOrFail($subject->getKey())->name,
        );
    }
}
