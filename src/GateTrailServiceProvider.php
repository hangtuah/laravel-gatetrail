<?php

declare(strict_types=1);

namespace HangTuah\GateTrail;

use HangTuah\GateTrail\Access\DefaultPermissionResolver;
use HangTuah\GateTrail\Access\DefaultRoleResolver;
use HangTuah\GateTrail\Access\EffectiveAccessMemo;
use HangTuah\GateTrail\Access\EffectiveAccessQueryBuilder;
use HangTuah\GateTrail\Access\EffectiveAccessResolver;
use HangTuah\GateTrail\Access\EffectiveAccessRowMapper;
use HangTuah\GateTrail\Access\FlushEffectiveAccessMemo;
use HangTuah\GateTrail\Access\RequestedDefinitionNormalizer;
use HangTuah\GateTrail\Assignments\AssignmentConflictQuery;
use HangTuah\GateTrail\Assignments\AssignmentDecisionMaker;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Assignments\AssignmentScopeProbe;
use HangTuah\GateTrail\Assignments\RevocationCoordinator;
use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\AuditContextBuilder;
use HangTuah\GateTrail\Audit\AuditFailureReporter;
use HangTuah\GateTrail\Audit\AuditModeResolver;
use HangTuah\GateTrail\Audit\CanonicalAuditJson;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\PermissionAssignmentSnapshotBuilder;
use HangTuah\GateTrail\Audit\PermissionSnapshotBuilder;
use HangTuah\GateTrail\Audit\RoleAssignmentSnapshotBuilder;
use HangTuah\GateTrail\Audit\RolePermissionSnapshotBuilder;
use HangTuah\GateTrail\Audit\RoleSnapshotBuilder;
use HangTuah\GateTrail\Audit\SensitiveAuditValueRedactor;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Concurrency\CoordinationRaceClassifier;
use HangTuah\GateTrail\Concurrency\RetryOrchestrator;
use HangTuah\GateTrail\Concurrency\RetryPolicy;
use HangTuah\GateTrail\Concurrency\TransientFailureClassifier;
use HangTuah\GateTrail\Console\InstallCommand;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\PermissionResolver;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Contracts\RoleResolver;
use HangTuah\GateTrail\Database\Schema\Ddl\DdlCompilerFactory;
use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspectorFactory;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaAdapterFactory;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Database\UniqueConstraintViolationClassifier;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Events\MutationEventDispatcher;
use HangTuah\GateTrail\Guards\DefaultGuardResolver;
use HangTuah\GateTrail\Guards\SubjectGuardResolver;
use HangTuah\GateTrail\Laravel\GateAuthorizationHook;
use HangTuah\GateTrail\Laravel\Middleware\MiddlewareParameterNormalizer;
use HangTuah\GateTrail\Laravel\Middleware\MiddlewareSetMatcher;
use HangTuah\GateTrail\Laravel\Middleware\MiddlewareSubjectResolver;
use HangTuah\GateTrail\Laravel\Middleware\PermissionMiddlewareEvaluator;
use HangTuah\GateTrail\Laravel\Middleware\RequireAllPermissions;
use HangTuah\GateTrail\Laravel\Middleware\RequireAllRoles;
use HangTuah\GateTrail\Laravel\Middleware\RequireAnyPermission;
use HangTuah\GateTrail\Laravel\Middleware\RequireAnyRole;
use HangTuah\GateTrail\Laravel\Middleware\RequirePermission;
use HangTuah\GateTrail\Laravel\Middleware\RequireRole;
use HangTuah\GateTrail\Laravel\Middleware\RoleMiddlewareEvaluator;
use HangTuah\GateTrail\Managers\DefaultAssignmentManager;
use HangTuah\GateTrail\Managers\DefaultPermissionManager;
use HangTuah\GateTrail\Managers\DefaultRoleManager;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\AfterCommitBoundary;
use HangTuah\GateTrail\Mutations\AssignmentIdentifierNormalizer;
use HangTuah\GateTrail\Mutations\AuditedMutationTransaction;
use HangTuah\GateTrail\Mutations\DeactivatedPermissionDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\DeactivatedRoleDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\DefinitionIdentifierNormalizer;
use HangTuah\GateTrail\Mutations\DefinitionInputValidator;
use HangTuah\GateTrail\Mutations\Handlers\AssignRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\CreatePermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\CreateRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\DeactivatePermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\DeactivateRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\GrantDirectPermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\GrantPermissionToRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\RestorePermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\RestoreRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\RevokeDirectPermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\RevokePermissionFromRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\RevokeRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\UpdatePermissionHandler;
use HangTuah\GateTrail\Mutations\Handlers\UpdateRoleHandler;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\PermissionAssignmentInputResolver;
use HangTuah\GateTrail\Mutations\PermissionDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\PermissionInputResolver;
use HangTuah\GateTrail\Mutations\RoleAssignmentInputResolver;
use HangTuah\GateTrail\Mutations\RoleDefinitionInputResolver;
use HangTuah\GateTrail\Mutations\RoleInputResolver;
use HangTuah\GateTrail\Mutations\RolePermissionDefinitionResolver;
use HangTuah\GateTrail\Mutations\RolePermissionInputResolver;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use HangTuah\GateTrail\Subjects\SubjectIdentityResolver;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Time\SystemClock;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Psr\Clock\ClockInterface;

final class GateTrailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/gatetrail.php',
            'gatetrail',
        );

        $this->app->singleton(IdentifierValidator::class);
        $this->app->singleton(
            AssignmentManager::class,
            DefaultAssignmentManager::class,
        );
        $this->app->singleton(
            RoleManager::class,
            DefaultRoleManager::class,
        );
        $this->app->singleton(
            PermissionManager::class,
            DefaultPermissionManager::class,
        );
        $this->app->singleton(AccessAuditWriter::class);
        $this->app->singleton(AuditContextBuilder::class);
        $this->app->singleton(AuditFailureReporter::class);
        $this->app->singleton(AuditModeResolver::class);
        $this->app->singleton(CanonicalAuditJson::class);
        $this->app->singleton(MetadataNormalizer::class);
        $this->app->singleton(PermissionAssignmentSnapshotBuilder::class);
        $this->app->singleton(PermissionSnapshotBuilder::class);
        $this->app->singleton(RoleAssignmentSnapshotBuilder::class);
        $this->app->singleton(RolePermissionSnapshotBuilder::class);
        $this->app->singleton(RoleSnapshotBuilder::class);
        $this->app->singleton(SensitiveAuditValueRedactor::class);
        $this->app->singleton(SensitiveMetadataRedactor::class);
        $this->app->singleton(AssignmentConflictQuery::class);
        // The snapshot query shapes. A concrete internal component with one
        // injected collaborator, so the package's explicit-singleton
        // convention applies exactly as it does to AssignmentConflictQuery.
        $this->app->singleton(EffectiveAccessQueryBuilder::class);
        // Stateless row mapping and stateless input normalization, so the
        // package's explicit-singleton convention applies to both.
        $this->app->singleton(EffectiveAccessRowMapper::class);
        $this->app->singleton(RequestedDefinitionNormalizer::class);
        // Stateless apart from the container it defers to, so the listener may
        // be a singleton — it resolves the scoped memo inside handle(), never
        // in its constructor.
        $this->app->singleton(FlushEffectiveAccessMemo::class);
        // Everything from here down is scoped rather than singleton. The memo
        // holds one scope's authorization answers, and a singleton that
        // captured it would carry one request's state into the next — and,
        // under a long-lived Octane or queue worker, would never be flushed
        // and never evicted.
        //
        // The two public adapters are scoped for the same reason, one step
        // removed: a singleton adapter would capture the scoped resolver at
        // first resolution and keep serving the stale one for the life of the
        // worker, which is the subtler version of the same leak.
        //
        // Each contract resolves *through* its concrete class rather than
        // being bound to it separately, so the contract and the concrete
        // adapter are one instance inside a scope and are flushed together.
        $this->app->scoped(EffectiveAccessMemo::class);
        $this->app->scoped(EffectiveAccessResolver::class);
        $this->app->scoped(DefaultPermissionResolver::class);
        $this->app->scoped(DefaultRoleResolver::class);
        $this->app->scoped(
            PermissionResolver::class,
            static fn (Application $app): DefaultPermissionResolver => $app->make(
                DefaultPermissionResolver::class,
            ),
        );
        $this->app->scoped(
            RoleResolver::class,
            static fn (Application $app): DefaultRoleResolver => $app->make(
                DefaultRoleResolver::class,
            ),
        );
        $this->app->singleton(AssignmentDecisionMaker::class);
        $this->app->singleton(AssignmentLockStore::class);
        $this->app->singleton(AssignmentScopeProbe::class);
        $this->app->singleton(RevocationCoordinator::class);
        $this->app->singleton(ActorIdentityResolver::class);
        // One shared subject-identity rule for every path that derives one,
        // so a read and a write can never disagree about who the subject is.
        // Stateless, so the package's explicit-singleton convention applies.
        $this->app->singleton(SubjectIdentityResolver::class);
        $this->app->singleton(AssignmentIdentifierNormalizer::class);
        $this->app->singleton(DefinitionIdentifierNormalizer::class);
        $this->app->singleton(DefinitionInputValidator::class);
        $this->app->singleton(UniqueConstraintViolationClassifier::class);
        $this->app->singleton(RetryPolicy::class);
        // The classifiers and the retry orchestrator are constructor-injected
        // collaborators, so the package's explicit-singleton convention applies
        // to them exactly as it does to UniqueConstraintViolationClassifier.
        // Each is stateless: the orchestrator's attempt counter, operation
        // closure and latest classified failure are locals belonging to one
        // call, never properties. ClassifiedConcurrencyFailure,
        // CoordinationPlan, CoordinationInsertRace and every exception stay
        // unregistered: they are per-invocation values and control flow, not
        // services.
        $this->app->singleton(TransientFailureClassifier::class);
        $this->app->singleton(CoordinationRaceClassifier::class);
        $this->app->singleton(RetryOrchestrator::class);
        $this->app->singleton(AssignRoleHandler::class);
        $this->app->singleton(CreatePermissionHandler::class);
        $this->app->singleton(CreateRoleHandler::class);
        $this->app->singleton(UpdatePermissionHandler::class);
        $this->app->singleton(UpdateRoleHandler::class);
        $this->app->singleton(DeactivatePermissionHandler::class);
        $this->app->singleton(DeactivateRoleHandler::class);
        $this->app->singleton(RestorePermissionHandler::class);
        $this->app->singleton(RestoreRoleHandler::class);
        $this->app->singleton(GrantDirectPermissionHandler::class);
        $this->app->singleton(GrantPermissionToRoleHandler::class);
        $this->app->singleton(RevokeRoleHandler::class);
        $this->app->singleton(ClockInterface::class, SystemClock::class);
        $this->app->singleton(SubjectGuardResolver::class);
        $this->app->scoped(GateAuthorizationHook::class);
        $this->app->singleton(MiddlewareParameterNormalizer::class);
        $this->app->singleton(MiddlewareSetMatcher::class);
        $this->app->singleton(MiddlewareSubjectResolver::class);
        $this->app->scoped(PermissionMiddlewareEvaluator::class);
        $this->app->scoped(RoleMiddlewareEvaluator::class);
        $this->app->singleton(DefaultGuardResolver::class);
        $this->app->singleton(RoleInputResolver::class);
        $this->app->singleton(RolePermissionDefinitionResolver::class);
        $this->app->singleton(PermissionInputResolver::class);
        $this->app->singleton(PermissionDefinitionInputResolver::class);
        $this->app->singleton(
            DeactivatedPermissionDefinitionInputResolver::class,
        );
        $this->app->singleton(PermissionAssignmentInputResolver::class);
        $this->app->singleton(RevokeDirectPermissionHandler::class);
        $this->app->singleton(RevokePermissionFromRoleHandler::class);
        $this->app->singleton(RoleAssignmentInputResolver::class);
        $this->app->singleton(RoleDefinitionInputResolver::class);
        $this->app->singleton(DeactivatedRoleDefinitionInputResolver::class);
        $this->app->singleton(RolePermissionInputResolver::class);
        $this->app->singleton(StrictMutationTransaction::class);
        $this->app->singleton(AfterCommitBoundary::class);
        $this->app->singleton(MutationEventDispatcher::class);
        $this->app->singleton(AuditedMutationTransaction::class);
        $this->app->singleton(
            MutationReasonValidator::class,
            static function (Application $app): MutationReasonValidator {
                $config = $app->make('config');
                $assignment = $config->get(
                    'gatetrail.assignments.require_reason',
                    true,
                );
                $definition = $config->get(
                    'gatetrail.definitions.require_reason',
                    false,
                );

                if (! is_bool($assignment)) {
                    throw new \InvalidArgumentException(
                        'GateTrail assignments.require_reason must be a boolean.',
                    );
                }

                if (! is_bool($definition)) {
                    throw new \InvalidArgumentException(
                        'GateTrail definitions.require_reason must be a boolean.',
                    );
                }

                return new MutationReasonValidator(
                    $assignment,
                    $definition,
                );
            },
        );
        $this->app->singleton(DdlCompilerFactory::class);
        $this->app->singleton(SchemaInspectorFactory::class);
        $this->app->singleton(SchemaAdapterFactory::class);
        $this->app->singleton(
            ModelRegistry::class,
            static function (Application $app): ModelRegistry {
                $models = $app->make('config')->get('gatetrail.models');

                /** @var array<string, mixed> $models */
                $models = is_array($models) ? $models : [];

                return new ModelRegistry($models);
            },
        );
        $this->app->singleton(
            SchemaManifest::class,
            static fn (): SchemaManifest => SchemaManifest::gateTrail(),
        );
        $this->app->singleton(
            SchemaContext::class,
            static function (Application $app): SchemaContext {
                $config = $app->make('config')->get('gatetrail');

                /** @var array<string, mixed> $config */
                $config = is_array($config) ? $config : [];

                return new SchemaContext(
                    $config,
                    $app->make(IdentifierValidator::class),
                    $app->make(SchemaManifest::class),
                );
            },
        );
    }

    public function boot(): void
    {
        // Registered before the console guard below, because request-local
        // memo coherence is a web, queue and CLI concern alike. A mutation
        // committed in this scope must clear this scope's memo wherever it
        // happens, and an early return would leave a served HTTP request
        // answering from state its own mutation had already replaced.
        $this->app->make('events')->listen(
            MutationCommitted::class,
            [FlushEffectiveAccessMemo::class, 'handle'],
        );

        // The Gate itself is a singleton, so its callback must not capture a
        // scoped resolver. Resolve the hook lazily for every check; Laravel's
        // scoped container then reuses it only inside the current request or
        // worker scope and replaces it when that scope is flushed.
        $app = $this->app;

        /** @param array<array-key, mixed> $arguments */
        $authorize = static function (
            mixed $subject,
            string $ability,
            array $arguments,
        ) use ($app): ?bool {
            return $app->make(GateAuthorizationHook::class)->authorize(
                $subject,
                $ability,
                $arguments,
            );
        };

        $this->app->make(GateContract::class)->before($authorize);
        $this->registerMiddlewareAliases($this->app->make(Router::class));

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/gatetrail.php' => config_path('gatetrail.php'),
        ], 'gatetrail-config');

        $this->commands([
            InstallCommand::class,
        ]);

        if (config('gatetrail.database.provisioning') !== 'package') {
            return;
        }

        $this->loadMigrationsFrom(
            __DIR__.'/../database/migrations',
        );
    }

    private function registerMiddlewareAliases(Router $router): void
    {
        $aliases = [
            'gatetrail.permission' => RequirePermission::class,
            'gatetrail.permission.any' => RequireAnyPermission::class,
            'gatetrail.permission.all' => RequireAllPermissions::class,
            'gatetrail.role' => RequireRole::class,
            'gatetrail.role.any' => RequireAnyRole::class,
            'gatetrail.role.all' => RequireAllRoles::class,
        ];

        foreach ($aliases as $alias => $middleware) {
            $existing = $router->getMiddleware()[$alias] ?? null;

            if ($existing !== null && $existing !== $middleware) {
                throw new LogicException(sprintf(
                    'GateTrail middleware alias [%s] is already registered.',
                    $alias,
                ));
            }

            if ($existing === null) {
                $router->aliasMiddleware($alias, $middleware);
            }
        }
    }
}
