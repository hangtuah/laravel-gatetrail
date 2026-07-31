<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use RuntimeException;

final class RecordingLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function __construct(
        private readonly bool $throws = false,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($this->throws) {
            throw new RuntimeException('The log channel is unavailable.');
        }

        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['level'] === 'error',
        ));
    }
}
