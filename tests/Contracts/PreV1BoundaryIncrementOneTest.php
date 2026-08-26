<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Contracts;

use HangTuah\GateTrail\Contracts\GateTrailEvent;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Exceptions\AssignmentScopeCollision;
use HangTuah\GateTrail\Exceptions\AuditWriteFailed;
use HangTuah\GateTrail\Exceptions\InvalidActor;
use HangTuah\GateTrail\Exceptions\InvalidAssignmentPeriod;
use HangTuah\GateTrail\Exceptions\InvalidAuditMetadata;
use HangTuah\GateTrail\Exceptions\InvalidModelConfiguration;
use HangTuah\GateTrail\Exceptions\ReasonRequired;
use HangTuah\GateTrail\Managers\DefaultPermissionManager;
use HangTuah\GateTrail\Managers\DefaultRoleManager;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\Mutations\ActorIdentity;
use HangTuah\GateTrail\Mutations\Handlers\CreatePermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\CreateRoleHandler;
use HangTuah\GateTrail\Support\ModelRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PreV1BoundaryIncrementOneTest extends TestCase
{
    public function test_create_contracts_describe_configurable_model_results(): void
    {
        foreach ([RoleManager::class, DefaultRoleManager::class] as $class) {
            $this->assertStringContainsString(
                'MutationResult<Model&RoleContract>',
                (string) (new ReflectionMethod($class, 'create'))
                    ->getDocComment(),
            );
        }

        $this->assertStringContainsString(
            'MutationResult<Model&RoleContract>',
            (string) (new ReflectionMethod(CreateRoleHandler::class, 'handle'))
                ->getDocComment(),
        );

        foreach (
            [PermissionManager::class, DefaultPermissionManager::class] as $class
        ) {
            $this->assertStringContainsString(
                'MutationResult<Model&PermissionContract>',
                (string) (new ReflectionMethod($class, 'create'))
                    ->getDocComment(),
            );
        }

        $this->assertStringContainsString(
            'MutationResult<Model&PermissionContract>',
            (string) (new ReflectionMethod(
                CreatePermissionHandler::class,
                'handle',
            ))->getDocComment(),
        );
    }

    public function test_model_registry_describes_configurable_definition_models(): void
    {
        $role = (string) (new ReflectionMethod(ModelRegistry::class, 'role'))
            ->getDocComment();
        $permission = (string) (new ReflectionMethod(
            ModelRegistry::class,
            'permission',
        ))->getDocComment();

        $this->assertStringContainsString(
            'class-string<Model&RoleContract>',
            $role,
        );
        $this->assertStringContainsString(
            'class-string<Model&PermissionContract>',
            $permission,
        );
    }

    public function test_event_contract_declares_safe_context(): void
    {
        $context = new ReflectionMethod(GateTrailEvent::class, 'context');

        $this->assertSame([], $context->getParameters());
        $this->assertSame('array', (string) $context->getReturnType());
        $this->assertStringContainsString(
            '@return array<string, mixed>',
            (string) $context->getDocComment(),
        );
    }

    public function test_audit_failure_is_package_constructed_gate_trail_event(): void
    {
        $event = new ReflectionClass(AuditPersistenceFailed::class);
        $factory = $event->getMethod('forRecord');

        $this->assertTrue($event->implementsInterface(GateTrailEvent::class));
        $this->assertTrue($event->getConstructor()?->isPrivate());
        $this->assertTrue($factory->isPublic());
        $this->assertStringContainsString(
            '@internal',
            (string) $factory->getDocComment(),
        );
    }

    public function test_internal_value_constructors_are_labelled_package_controlled(): void
    {
        $resultConstructor = (new ReflectionClass(MutationResult::class))
            ->getConstructor();
        $actor = new ReflectionClass(ActorIdentity::class);
        $actorConstructor = $actor->getConstructor();

        $this->assertTrue($resultConstructor?->isPublic());
        $this->assertStringContainsString(
            '@internal',
            (string) $resultConstructor?->getDocComment(),
        );
        $this->assertStringContainsString(
            '@internal',
            (string) $actor->getDocComment(),
        );
        $this->assertTrue($actorConstructor?->isPublic());
        $this->assertStringContainsString(
            '@internal',
            (string) $actorConstructor?->getDocComment(),
        );
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function packageConstructedExceptions(): iterable
    {
        yield AssignmentScopeCollision::class => [AssignmentScopeCollision::class];
        yield AuditWriteFailed::class => [AuditWriteFailed::class];
        yield InvalidActor::class => [InvalidActor::class];
        yield InvalidAssignmentPeriod::class => [InvalidAssignmentPeriod::class];
        yield InvalidAuditMetadata::class => [InvalidAuditMetadata::class];
        yield InvalidModelConfiguration::class => [InvalidModelConfiguration::class];
        yield ReasonRequired::class => [ReasonRequired::class];
    }

    /**
     * @param  class-string  $exception
     */
    #[DataProvider('packageConstructedExceptions')]
    public function test_package_constructed_exceptions_have_private_constructors(
        string $exception,
    ): void {
        $constructor = (new ReflectionClass($exception))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }
}
