<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use ReflectionProperty;
use Throwable;

/**
 * Answers whether a connection defers an after-commit callback to the
 * outermost commit of a caller-owned transaction.
 *
 * The check registers nothing, writes nothing, and executes no callback, so a
 * mutation that turns out to be unchanged leaves no trace behind it.
 *
 * Laravel 12 and 13 expose no public way to ask a connection this question.
 * `Connection::afterCommit()` is the only public entry point and it always
 * either registers a callback or throws, and `Connection` has no accessor for
 * its transactions manager. Everything else needed here is public API in both
 * versions: `DatabaseTransactionsManager::callbackApplicableTransactions()`,
 * and `DatabaseTransactionRecord::$connection`. Reading the one protected
 * property is therefore the only non-mutating option, and it is confined to
 * this class.
 *
 * The property is named identically and is protected in Laravel 12 and 13. If
 * a future release renames or removes it, {@see self::manager()} returns null
 * and GateTrail reports an unsupported boundary rather than guessing, so the
 * failure is loud and fails closed. `AfterCommitBoundaryTest` asserts the
 * property still exists and that this class agrees with what the framework
 * actually does, so the drift surfaces as a test failure.
 *
 * @internal
 */
final readonly class AfterCommitBoundary
{
    private const MANAGER_PROPERTY = 'transactionsManager';

    /**
     * Replicates the decision `DatabaseTransactionsManager::addCallback()`
     * would make, without making it.
     *
     * That method attaches to the last applicable pending transaction and
     * otherwise runs the callback immediately. Running immediately would put
     * the audit inside the caller's transaction, so it counts as unsupported.
     * The pending transaction must also belong to this connection: Laravel
     * picks the globally last one, and deferring GateTrail's audit to another
     * connection's commit is not the boundary this package promises.
     */
    public function defersCallbacks(Connection $connection): bool
    {
        $manager = $this->manager($connection);

        if (! $manager instanceof DatabaseTransactionsManager) {
            return false;
        }

        $applicable = $manager->callbackApplicableTransactions()->last();

        if (! $applicable instanceof DatabaseTransactionRecord) {
            return false;
        }

        return $applicable->connection === $connection->getName();
    }

    private function manager(
        Connection $connection,
    ): ?DatabaseTransactionsManager {
        try {
            $property = new ReflectionProperty(
                $connection,
                self::MANAGER_PROPERTY,
            );
            $manager = $property->getValue($connection);
        } catch (Throwable) {
            return null;
        }

        return $manager instanceof DatabaseTransactionsManager
            ? $manager
            : null;
    }
}
