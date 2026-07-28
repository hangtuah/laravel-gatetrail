<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema;

interface SchemaAdapter
{
    public function driver(): string;

    public function physicalPrimaryKeyName(string $logicalName): ?string;

    public function caseSensitiveCollation(): ?string;

    public function requiresInlineCheckConstraints(): bool;
}
