<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Database\Schema\Ddl\Oracle;

use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use InvalidArgumentException;

/**
 * @internal
 */
final readonly class OracleSchemaOptions
{
    /**
     * @param  array<string, mixed>  $sequences
     * @param  array<string, mixed>  $triggers
     */
    private function __construct(
        public OracleGeneratedKeyStrategy $generatedKeys,
        private array $sequences,
        private array $triggers,
        private IdentifierValidator $identifiers,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(
        array $config,
        IdentifierValidator $identifiers,
    ): self {
        $sequences = $config['sequences'] ?? [];
        $triggers = $config['triggers'] ?? [];

        if (! is_array($sequences)) {
            throw new InvalidArgumentException(
                'GateTrail oracle.sequences must be an array.',
            );
        }

        if (! is_array($triggers)) {
            throw new InvalidArgumentException(
                'GateTrail oracle.triggers must be an array.',
            );
        }

        /** @var array<string, mixed> $sequences */
        /** @var array<string, mixed> $triggers */
        return new self(
            OracleGeneratedKeyStrategy::fromConfig(
                $config['generated_keys'] ?? null,
            ),
            $sequences,
            $triggers,
            $identifiers,
        );
    }

    /**
     * @param  list<string>  $generatedTableKeys
     */
    public function validateFor(array $generatedTableKeys): void
    {
        if ($this->generatedKeys !== OracleGeneratedKeyStrategy::SequenceTrigger) {
            return;
        }

        foreach ($generatedTableKeys as $tableKey) {
            $this->sequence($tableKey);
            $this->trigger($tableKey);
        }
    }

    public function sequence(string $tableKey): string
    {
        return $this->configuredIdentifier(
            $this->sequences,
            'sequence',
            $tableKey,
        );
    }

    public function trigger(string $tableKey): string
    {
        return $this->configuredIdentifier(
            $this->triggers,
            'trigger',
            $tableKey,
        );
    }

    /**
     * @param  array<string, mixed>  $objects
     */
    private function configuredIdentifier(
        array $objects,
        string $objectType,
        string $tableKey,
    ): string {
        $configured = $objects[$tableKey] ?? null;

        if (! is_string($configured)) {
            throw new InvalidArgumentException(
                sprintf(
                    'GateTrail oracle.%ss.%s must be a string.',
                    $objectType,
                    $tableKey,
                ),
            );
        }

        $this->identifiers->validate(
            $configured,
            sprintf('Oracle %s [%s]', $objectType, $tableKey),
        );

        return $configured;
    }
}
