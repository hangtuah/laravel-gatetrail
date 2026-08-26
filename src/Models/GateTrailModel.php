<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Models;

use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Internal base for GateTrail's supported model extension points.
 *
 * Consumers extend one of the six concrete package models, not this class
 * directly. Those subclasses inherit stable connection and table resolution
 * through SchemaContext. The only valid table keys are: roles, permissions,
 * role_assignments, permission_assignments, role_permissions,
 * assignment_locks, and access_audits.
 *
 * @internal
 */
abstract class GateTrailModel extends Model
{
    /**
     * Resolve the configured GateTrail connection, falling back to Eloquent's
     * normal model connection when the package connection is null.
     */
    public function getConnectionName(): ?string
    {
        return app(SchemaContext::class)->connectionName()
            ?? parent::getConnectionName();
    }

    /**
     * Resolve the configured schema, prefix, and table for this model's key.
     */
    public function getTable(): string
    {
        return app(SchemaContext::class)->table($this->tableKey());
    }

    /**
     * Return one of the seven GateTrail table keys documented on this class.
     */
    abstract protected function tableKey(): string;
}
