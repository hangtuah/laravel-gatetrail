<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

use DateTimeInterface;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\AssignmentScopeCollision;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use stdClass;

final readonly class AssignmentLockStore
{
    public function __construct(
        private DatabaseManager $database,
        private SchemaContext $schema,
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
            $connection
                ->table($this->schema->table('assignment_locks'))
                ->insert([
                    'assignment_kind' => $scope->kind()->value,
                    'scope_hash' => $scope->hash(),
                    'scope_key' => $scope->key(),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

            $row = $this->findForUpdate($scope);
        }

        if ($row === null || $row->scope_key !== $scope->key()) {
            throw AssignmentScopeCollision::forScope($scope);
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
