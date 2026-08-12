<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use DateTimeInterface;
use HangTuah\GateTrail\Concurrency\ClassifiedConcurrencyFailure;
use HangTuah\GateTrail\Concurrency\CoordinationInsertRace;
use HangTuah\GateTrail\Concurrency\CoordinationRaceClassifier;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\AssignmentScopeCollision;
use HangTuah\GateTrail\Exceptions\UnsupportedDatabaseDriver;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use LogicException;
use stdClass;

final readonly class AssignmentLockStore
{
    public function __construct(
        private DatabaseManager $database,
        private SchemaContext $schema,
        private CoordinationRaceClassifier $races,
    ) {}

    public function acquire(
        AssignmentScope $scope,
        DateTimeInterface $timestamp,
    ): void {
        $connection = $this->connection();

        if ($connection->transactionLevel() === 0) {
            throw new LogicException(
                'GateTrail assignment locks must be acquired inside a database transaction.',
            );
        }

        $row = $this->findForUpdate($scope);

        if ($row === null) {
            $this->insert($connection, $scope, $timestamp);

            $row = $this->findForUpdate($scope);
        }

        if ($row === null || $row->scope_key !== $scope->key()) {
            throw AssignmentScopeCollision::forScope($scope);
        }
    }

    /**
     * Inserts the coordination row, converting only a confirmed race on this
     * exact statement into the internal retry marker.
     *
     * This is the single statement in GateTrail that can lose a coordination
     * race, and the `catch` is deliberately no wider than it. The reads around
     * it are not wrapped: the `SELECT ... FOR UPDATE` before it and the re-read
     * after it must keep failing exactly as they do today, because a failure
     * there is not a lost insert race whatever its diagnostics happen to say.
     * That narrowness is what makes
     * {@see CoordinationRaceClassifier}'s positional precondition structurally
     * true rather than a convention.
     *
     * An unconfirmed failure — another table, a trigger, a definition
     * constraint, a missing table, anything at all that the classifier does not
     * positively recognise — is rethrown as the same object with its own chain
     * intact.
     */
    private function insert(
        Connection $connection,
        AssignmentScope $scope,
        DateTimeInterface $timestamp,
    ): void {
        try {
            $connection
                ->table($this->schema->table('assignment_locks'))
                ->insert([
                    'assignment_kind' => $scope->kind()->value,
                    'scope_hash' => $scope->hash(),
                    'scope_key' => $scope->key(),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        } catch (QueryException $exception) {
            $failure = $this->classify($exception, $connection);

            if ($failure === null) {
                throw $exception;
            }

            // Confirmed. The driver exception has been reduced to four safe
            // scalars, so drop it before the marker exists: nothing may chain
            // it, store it or carry its message, SQL or bindings onward.
            unset($exception);

            throw CoordinationInsertRace::confirmed($failure);
        }
    }

    /**
     * An unsupported driver means GateTrail cannot confirm identity, which is
     * the same answer as "not confirmed". Raising
     * {@see UnsupportedDatabaseDriver} from inside this `catch` would replace
     * the deployment's real database failure with a complaint about driver
     * support, and would do it for every insert failure rather than only this
     * one.
     */
    private function classify(
        QueryException $exception,
        Connection $connection,
    ): ?ClassifiedConcurrencyFailure {
        try {
            return $this->races->classify(
                $exception,
                $connection->getDriverName(),
            );
        } catch (UnsupportedDatabaseDriver) {
            return null;
        }
    }

    private function findForUpdate(AssignmentScope $scope): ?stdClass
    {
        /** @var stdClass|null */
        return $this->connection()
            ->table($this->schema->table('assignment_locks'))
            ->where('assignment_kind', $scope->kind()->value)
            ->where('scope_hash', $scope->hash())
            ->lockForUpdate()
            ->first();
    }

    private function connection(): Connection
    {
        return $this->database->connection($this->schema->connectionName());
    }
}
