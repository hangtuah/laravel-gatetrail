<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Exceptions;

use InvalidArgumentException;

final class InvalidModelConfiguration extends InvalidArgumentException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function missing(string $key): self
    {
        return new self(sprintf(
            'GateTrail model [%s] must be a non-empty class string.',
            $key,
        ));
    }

    public static function notEloquentModel(string $key, string $class): self
    {
        return new self(sprintf(
            'GateTrail model [%s] class [%s] must extend Eloquent Model.',
            $key,
            $class,
        ));
    }

    public static function missingContract(
        string $key,
        string $class,
        string $contract,
    ): self {
        return new self(sprintf(
            'GateTrail model [%s] class [%s] must implement [%s].',
            $key,
            $class,
            $contract,
        ));
    }

    public static function missingSoftDeletes(string $key, string $class): self
    {
        return new self(sprintf(
            'GateTrail model [%s] class [%s] must use SoftDeletes.',
            $key,
            $class,
        ));
    }

    public static function missingBaseModel(
        string $key,
        string $class,
        string $base,
    ): self {
        return new self(sprintf(
            'GateTrail model [%s] class [%s] must extend [%s].',
            $key,
            $class,
            $base,
        ));
    }
}
