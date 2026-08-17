<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Access;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use HangTuah\GateTrail\Access\EffectiveAccessMemo;
use HangTuah\GateTrail\Access\EffectiveAccessMemoKey;
use HangTuah\GateTrail\Access\EffectiveAccessSnapshot;
use HangTuah\GateTrail\Subjects\SubjectIdentity;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionObject;
use ReflectionProperty;

/**
 * The scoped memo store and its key, in isolation.
 *
 * Two things here are load-bearing and cannot be observed end to end.
 *
 * **Key collision safety.** The subject morph type is a class name or a
 * consumer-chosen morph alias, and the guard is a configuration string. Both
 * can contain any byte, including whatever separator a naive key would pick.
 * A key built by plain concatenation lets two genuinely different subjects
 * share one entry — and therefore one authorization answer. The tests below
 * feed the separator characters straight into both fields.
 *
 * **Eviction bookkeeping.** A bound that leaks its recency index still grows
 * without limit, just more slowly. Size is asserted against the store's real
 * internal array rather than against a debug accessor added for the test.
 */
final class EffectiveAccessMemoTest extends TestCase
{
    private const CAPACITY = 64;

    /**
     * Pairs that a plainly concatenated key would collapse into one entry.
     *
     * @return array<string, array{array{string, int, string}, array{string, int, string}}>
     */
    public static function collidingTuples(): array
    {
        return [
            'pipe moved between type and guard' => [
                ['App\\User|web', 7, 'api'],
                ['App\\User', 7, 'web|api'],
            ],
            'colon moved between type and guard' => [
                ['App\\User:web', 7, 'api'],
                ['App\\User', 7, 'web:api'],
            ],
            'separator absorbed into the type' => [
                ['App\\User|7|web', 7, 'web'],
                ['App\\User', 7, '7|web|web'],
            ],
            'empty guard versus shifted text' => [
                ['App\\User', 7, ''],
                ['App\\User|', 7, ''],
            ],
            'digits that could read as a length prefix' => [
                ['3:abc', 7, 'web'],
                ['abc', 7, 'web'],
            ],
        ];
    }

    // ----------------------------------------------------------------- The key

    public function test_the_key_is_stable_for_one_evaluation(): void
    {
        $first = $this->key('App\\Models\\User', 7, 'web', '2026-08-17 12:00:00');
        $second = $this->key('App\\Models\\User', 7, 'web', '2026-08-17 12:00:00');

        $this->assertSame($first->value(), $second->value());
    }

    /**
     * @param  array{string, int, string}  $left
     * @param  array{string, int, string}  $right
     */
    #[DataProvider('collidingTuples')]
    public function test_the_key_cannot_collide_across_separators(
        array $left,
        array $right,
    ): void {
        $this->assertNotSame(
            $this->key($left[0], $left[1], $left[2], '2026-08-17 12:00:00')->value(),
            $this->key($right[0], $right[1], $right[2], '2026-08-17 12:00:00')->value(),
        );
    }

    public function test_every_component_changes_the_key(): void
    {
        $base = $this->key('App\\Models\\User', 7, 'web', '2026-08-17 12:00:00');

        foreach ([
            'morph type' => $this->key('App\\Models\\Admin', 7, 'web', '2026-08-17 12:00:00'),
            'identifier' => $this->key('App\\Models\\User', 8, 'web', '2026-08-17 12:00:00'),
            'guard' => $this->key('App\\Models\\User', 7, 'api', '2026-08-17 12:00:00'),
            'second' => $this->key('App\\Models\\User', 7, 'web', '2026-08-17 12:00:01'),
        ] as $component => $other) {
            $this->assertNotSame(
                $base->value(),
                $other->value(),
                sprintf('The [%s] must be part of the key.', $component),
            );
        }
    }

    /**
     * The same instant expressed in another timezone is the same second, so it
     * must be the same entry.
     */
    public function test_the_key_uses_an_absolute_second(): void
    {
        $utc = EffectiveAccessMemoKey::for(
            new SubjectIdentity('App\\Models\\User', 7),
            'web',
            new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC')),
        );
        $elsewhere = EffectiveAccessMemoKey::for(
            new SubjectIdentity('App\\Models\\User', 7),
            'web',
            new DateTimeImmutable('2026-08-17 20:00:00', new DateTimeZone('Asia/Kuala_Lumpur')),
        );

        $this->assertSame($utc->value(), $elsewhere->value());
    }

    public function test_the_key_is_final_readonly_and_internal(): void
    {
        $reflection = new ReflectionClass(EffectiveAccessMemoKey::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
        $this->assertStringContainsString(
            '@internal',
            (string) $reflection->getDocComment(),
        );
        $this->assertTrue($reflection->getConstructor()?->isPrivate());
    }

    // ------------------------------------------------------------- Basic store

    public function test_a_missing_key_returns_null(): void
    {
        $this->assertNull($this->memo()->get($this->numberedKey(1)));
    }

    public function test_a_stored_snapshot_is_returned_identically(): void
    {
        $memo = $this->memo();
        $snapshot = $this->snapshot(1);

        $memo->put($this->numberedKey(1), $snapshot);

        $this->assertSame($snapshot, $memo->get($this->numberedKey(1)));
    }

    public function test_clear_removes_every_entry_and_all_recency_state(): void
    {
        $memo = $this->memo();

        for ($index = 1; $index <= 10; $index++) {
            $memo->put($this->numberedKey($index), $this->snapshot($index));
        }

        $memo->clear();

        $this->assertSame(0, $this->entryCount($memo));
        $this->assertSame([], $this->entries($memo));

        for ($index = 1; $index <= 10; $index++) {
            $this->assertNull($memo->get($this->numberedKey($index)));
        }
    }

    // ------------------------------------------------------------------- Bound

    public function test_the_capacity_is_exactly_sixty_four(): void
    {
        $capacity = (new ReflectionClass(EffectiveAccessMemo::class))
            ->getConstant('CAPACITY');

        $this->assertSame(self::CAPACITY, $capacity);
        $this->assertFalse(
            (new ReflectionClass(EffectiveAccessMemo::class))
                ->getReflectionConstant('CAPACITY')?->isPublic(),
            'The bound is internal, not a public guarantee.',
        );
    }

    public function test_sixty_four_entries_all_remain(): void
    {
        $memo = $this->memo();

        for ($index = 1; $index <= self::CAPACITY; $index++) {
            $memo->put($this->numberedKey($index), $this->snapshot($index));
        }

        $this->assertSame(self::CAPACITY, $this->entryCount($memo));

        for ($index = 1; $index <= self::CAPACITY; $index++) {
            $this->assertNotNull($memo->get($this->numberedKey($index)));
        }
    }

    public function test_the_sixty_fifth_entry_evicts_the_least_recently_used(): void
    {
        $memo = $this->memo();

        for ($index = 1; $index <= self::CAPACITY; $index++) {
            $memo->put($this->numberedKey($index), $this->snapshot($index));
        }

        $memo->put($this->numberedKey(65), $this->snapshot(65));

        $this->assertSame(self::CAPACITY, $this->entryCount($memo));
        $this->assertNull($memo->get($this->numberedKey(1)), 'Entry 1 was oldest.');
        $this->assertNotNull($memo->get($this->numberedKey(2)));
        $this->assertNotNull($memo->get($this->numberedKey(65)));
    }

    /**
     * The difference between least-recently-*used* and least-recently-*added*.
     * Reading entry 1 makes entry 2 the eviction candidate instead.
     */
    public function test_a_read_promotes_an_entry_past_the_eviction_line(): void
    {
        $memo = $this->memo();

        for ($index = 1; $index <= self::CAPACITY; $index++) {
            $memo->put($this->numberedKey($index), $this->snapshot($index));
        }

        $this->assertNotNull($memo->get($this->numberedKey(1)));

        $memo->put($this->numberedKey(65), $this->snapshot(65));

        $this->assertNotNull($memo->get($this->numberedKey(1)), 'Promoted by the read.');
        $this->assertNull($memo->get($this->numberedKey(2)), 'Now the oldest.');
        $this->assertSame(self::CAPACITY, $this->entryCount($memo));
    }

    public function test_replacing_an_existing_key_does_not_grow_the_store(): void
    {
        $memo = $this->memo();

        for ($index = 1; $index <= self::CAPACITY; $index++) {
            $memo->put($this->numberedKey($index), $this->snapshot($index));
        }

        $replacement = $this->snapshot(1);
        $memo->put($this->numberedKey(1), $replacement);

        $this->assertSame(self::CAPACITY, $this->entryCount($memo));
        $this->assertSame($replacement, $memo->get($this->numberedKey(1)));
        $this->assertNotNull(
            $memo->get($this->numberedKey(2)),
            'A replacement must evict nothing.',
        );
    }

    /**
     * The shape a long-lived queue worker actually produces: many subjects
     * across many evaluation seconds.
     */
    public function test_many_subjects_and_seconds_never_exceed_the_bound(): void
    {
        $memo = $this->memo();

        for ($second = 0; $second < 20; $second++) {
            for ($subject = 1; $subject <= 25; $subject++) {
                $memo->put(
                    $this->key(
                        'App\\Models\\User',
                        $subject,
                        'web',
                        sprintf('2026-08-17 12:00:%02d', $second),
                    ),
                    $this->snapshot($subject),
                );

                $this->assertLessThanOrEqual(self::CAPACITY, $this->entryCount($memo));
            }
        }

        $this->assertSame(self::CAPACITY, $this->entryCount($memo));
    }

    /**
     * An evicted key must leave nothing behind — not in the entry map, and not
     * in any auxiliary index. The store keeps exactly one array, which is what
     * makes that provable.
     */
    public function test_eviction_leaves_no_stale_bookkeeping(): void
    {
        $memo = $this->memo();

        for ($index = 1; $index <= self::CAPACITY + 20; $index++) {
            $memo->put($this->numberedKey($index), $this->snapshot($index));
        }

        $entries = $this->entries($memo);

        $this->assertCount(self::CAPACITY, $entries);

        foreach (array_keys($entries) as $remaining) {
            $this->assertIsString($remaining);
        }

        for ($index = 1; $index <= 20; $index++) {
            $this->assertArrayNotHasKey(
                $this->numberedKey($index)->value(),
                $entries,
            );
        }

        $properties = (new ReflectionObject($memo))->getProperties();

        $this->assertCount(
            1,
            $properties,
            'One array is the whole bookkeeping, so nothing can be left stale.',
        );
    }

    // --------------------------------------------------------- Retained state

    public function test_it_is_final_internal_and_holds_only_snapshots(): void
    {
        $reflection = new ReflectionClass(EffectiveAccessMemo::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertStringContainsString(
            '@internal',
            (string) $reflection->getDocComment(),
        );

        $memo = $this->memo();
        $memo->put($this->numberedKey(1), $this->snapshot(1));

        foreach ($this->entries($memo) as $held) {
            $this->assertInstanceOf(EffectiveAccessSnapshot::class, $held);
        }
    }

    /**
     * Nothing that could execute a statement, reach a subject, or outlive the
     * scope may hide in the store.
     */
    public function test_it_retains_no_implementation_state(): void
    {
        $memo = $this->memo();
        $memo->put($this->numberedKey(1), $this->snapshot(1));

        foreach ((new ReflectionObject($memo))->getProperties() as $property) {
            $value = $property->getValue($memo);

            $this->assertIsArray($value);

            foreach ($value as $held) {
                foreach ([
                    Model::class,
                    ConnectionInterface::class,
                    Container::class,
                    Collection::class,
                    Closure::class,
                ] as $forbidden) {
                    $this->assertNotInstanceOf($forbidden, $held);
                }
            }
        }
    }

    public function test_its_public_surface_is_get_put_and_clear(): void
    {
        $names = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(EffectiveAccessMemo::class))
                ->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        sort($names);

        $this->assertSame(['clear', 'get', 'put'], $names);
    }

    // ------------------------------------------------------------------ Helpers

    private function memo(): EffectiveAccessMemo
    {
        return new EffectiveAccessMemo;
    }

    private function key(
        string $type,
        int $id,
        string $guard,
        string $instant,
    ): EffectiveAccessMemoKey {
        return EffectiveAccessMemoKey::for(
            new SubjectIdentity($type, $id),
            $guard,
            new DateTimeImmutable($instant, new DateTimeZone('UTC')),
        );
    }

    private function numberedKey(int $index): EffectiveAccessMemoKey
    {
        return $this->key('App\\Models\\User', $index, 'web', '2026-08-17 12:00:00');
    }

    private function snapshot(int $id): EffectiveAccessSnapshot
    {
        return EffectiveAccessSnapshot::of(
            new SubjectIdentity('App\\Models\\User', $id),
            'web',
            new DateTimeImmutable('2026-08-17 12:00:00', new DateTimeZone('UTC')),
            [],
            [],
        );
    }

    /**
     * @return array<string, EffectiveAccessSnapshot>
     */
    private function entries(EffectiveAccessMemo $memo): array
    {
        /** @var array<string, EffectiveAccessSnapshot> $entries */
        $entries = (new ReflectionProperty($memo, 'entries'))->getValue($memo);

        return $entries;
    }

    private function entryCount(EffectiveAccessMemo $memo): int
    {
        return count($this->entries($memo));
    }
}
