<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Models;

use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Database\Eloquent\Model;

abstract class GateTrailModel extends Model
{
    public function getConnectionName(): ?string
    {
        return app(SchemaContext::class)->connectionName()
            ?? parent::getConnectionName();
    }

    public function getTable(): string
    {
        return app(SchemaContext::class)->table($this->tableKey());
    }

    abstract protected function tableKey(): string;
}
