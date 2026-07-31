<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Mutation;

use HangTuah\GateTrail\Mutations\AfterCommitBoundary;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Connection;
use ReflectionProperty;
use RuntimeException;

/**
 * Guards the one framework internal GateTrail reads to answer "will this
 * connection defer an after-commit callback?" without registering one.
 */
final class AfterCommitBoundaryTest extends TestCase
{
    public function test_the_framework_property_it_reads_still_exists(): void
    {
        // Laravel 12 and 13 both declare this as a protected property with no
        // public accessor. A rename must fail here rather than silently
        // downgrade every caller-owned non-strict mutation.
        $this->assertTrue(
            (new ReflectionProperty(
                Connection::class,
                'transactionsManager',
            ))->isProtected(),
        );
    }

    public function test_it_agrees_with_the_framework_outside_a_transaction(): void
    {
        $connection = $this->connection();

        $this->assertFalse($this->boundary()->defersCallbacks($connection));
        $this->assertSame('immediate', $this->observe($connection));
    }

    public function test_it_agrees_with_the_framework_inside_a_transaction(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $this->assertTrue(
                $this->boundary()->defersCallbacks($connection),
            );
            $this->assertSame('deferred', $this->observe($connection));
        } finally {
            $connection->rollBack();
        }
    }

    public function test_it_agrees_with_the_framework_without_a_manager(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();
        $connection->unsetTransactionManager();

        try {
            $this->assertFalse(
                $this->boundary()->defersCallbacks($connection),
            );
            $this->assertSame('threw', $this->observe($connection));
        } finally {
            $connection->rollBack();
        }
    }

    public function test_it_agrees_with_the_framework_for_an_untracked_transaction(): void
    {
        $connection = $this->connection();

        // The manager exists but never saw this transaction begin, so
        // `addCallback()` would run the callback immediately.
        $connection->unsetTransactionManager();
        $connection->beginTransaction();
        $connection->setTransactionManager($this->app->make('db.transactions'));

        try {
            $this->assertFalse(
                $this->boundary()->defersCallbacks($connection),
            );
            $this->assertSame('immediate', $this->observe($connection));
        } finally {
            $connection->rollBack();
        }
    }

    public function test_a_pending_transaction_on_another_connection_is_rejected(): void
    {
        config()->set('database.connections.secondary', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $connection = $this->connection();
        $secondary = $this->app['db']->connection('secondary');

        $connection->beginTransaction();
        $secondary->beginTransaction();

        try {
            // Laravel attaches a callback to the globally last pending
            // transaction, which now belongs to another connection. Deferring
            // a GateTrail audit to a foreign commit is not the boundary this
            // package promises, so it fails closed.
            $this->assertFalse(
                $this->boundary()->defersCallbacks($connection),
            );
        } finally {
            $secondary->rollBack();
            $connection->rollBack();
        }
    }

    public function test_the_check_registers_no_callback(): void
    {
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            $boundary = $this->boundary();

            for ($i = 0; $i < 3; $i++) {
                $this->assertTrue($boundary->defersCallbacks($connection));
            }

            $this->assertSame([], $this->pendingCallbacks());
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * Reports what the framework actually does with an after-commit callback
     * in the connection's current state.
     */
    private function observe(Connection $connection): string
    {
        $ran = false;

        try {
            $connection->afterCommit(static function () use (&$ran): void {
                $ran = true;
            });
        } catch (RuntimeException) {
            return 'threw';
        }

        return $ran ? 'immediate' : 'deferred';
    }

    /**
     * The callbacks Laravel would execute at the next outermost commit.
     *
     * @return list<callable>
     */
    private function pendingCallbacks(): array
    {
        $applicable = $this->app->make('db.transactions')
            ->callbackApplicableTransactions()
            ->last();

        return $applicable === null ? [] : $applicable->getCallbacks();
    }

    private function boundary(): AfterCommitBoundary
    {
        return $this->app->make(AfterCommitBoundary::class);
    }

    private function connection(): Connection
    {
        $connection = $this->app['db']->connection('testing');

        $this->assertInstanceOf(Connection::class, $connection);

        return $connection;
    }
}
