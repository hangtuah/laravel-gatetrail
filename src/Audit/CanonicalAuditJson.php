<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * @internal
 */
final class CanonicalAuditJson
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function object(array $values): string
    {
        $object = $values === [] ? new stdClass : $values;

        try {
            return json_encode(
                $object,
                JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'GateTrail audit data must be JSON-safe.',
                0,
                $exception,
            );
        }
    }

    public function identifier(mixed $value, string $field): string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }

        if (
            ! is_string($value)
            || preg_match('/^\d+$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail audit field [%s] must be an unsigned decimal identifier.',
                    $field,
                ),
            );
        }

        $canonical = ltrim($value, '0');

        return $canonical === '' ? '0' : $canonical;
    }

    public function timestamp(
        mixed $value,
        string $field,
    ): string {
        if (! $value instanceof DateTimeInterface) {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail audit field [%s] must be a datetime.',
                    $field,
                ),
            );
        }

        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
