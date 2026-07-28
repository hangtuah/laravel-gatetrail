<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

use DateTimeImmutable;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use InvalidArgumentException;
use JsonException;

/**
 * @internal
 */
final readonly class PendingAuditRecord
{
    public function __construct(
        public string $event,
        public ActorIdentity $actor,
        public ?string $subjectType,
        public int|string|null $subjectId,
        public string $targetType,
        public int|string $targetId,
        public ?string $before,
        public ?string $after,
        public ?string $reason,
        public string $context,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $requestId,
        public DateTimeImmutable $createdAt,
    ) {
        if (($subjectType === null) !== ($subjectId === null)) {
            throw new InvalidArgumentException(
                'GateTrail audit subject type and ID must both be present or absent.',
            );
        }

        $this->assertJsonObject($before, 'before', true);
        $this->assertJsonObject($after, 'after', true);
        $this->assertJsonObject($context, 'context');
    }

    /**
     * @return array<string, mixed>
     */
    public function databaseAttributes(): array
    {
        return [
            'event' => $this->event,
            'actor_type' => $this->actor->type,
            'actor_id' => $this->actor->id,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'before' => $this->before,
            'after' => $this->after,
            'reason' => $this->reason,
            'context' => $this->context,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'request_id' => $this->requestId,
            'created_at' => $this->createdAt,
        ];
    }

    private function assertJsonObject(
        ?string $document,
        string $name,
        bool $nullable = false,
    ): void {
        if ($document === null && $nullable) {
            return;
        }

        try {
            $decoded = json_decode(
                $document ?? '',
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail audit %s must be a valid JSON object.',
                    $name,
                ),
                0,
                $exception,
            );
        }

        if (! is_object($decoded)) {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail audit %s must be a JSON object.',
                    $name,
                ),
            );
        }
    }
}
