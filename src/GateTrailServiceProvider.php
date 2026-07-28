<?php

declare(strict_types=1);

namespace HangTuah\GateTrail;

use HangTuah\GateTrail\Assignments\AssignmentConflictQuery;
use HangTuah\GateTrail\Assignments\AssignmentDecisionMaker;
use HangTuah\GateTrail\Assignments\AssignmentLockStore;
use HangTuah\GateTrail\Audit\AccessAuditWriter;
use HangTuah\GateTrail\Audit\MetadataNormalizer;
use HangTuah\GateTrail\Audit\SensitiveMetadataRedactor;
use HangTuah\GateTrail\Database\Schema\Ddl\DdlCompilerFactory;
use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspectorFactory;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaAdapterFactory;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Guards\SubjectGuardResolver;
use HangTuah\GateTrail\Mutations\ActorIdentityResolver;
use HangTuah\GateTrail\Mutations\MutationReasonValidator;
use HangTuah\GateTrail\Mutations\RoleInputResolver;
use HangTuah\GateTrail\Mutations\StrictMutationTransaction;
use HangTuah\GateTrail\Support\ModelRegistry;
use HangTuah\GateTrail\Time\SystemClock;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
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
        $this->app->singleton(AccessAuditWriter::class);
        $this->app->singleton(MetadataNormalizer::class);
        $this->app->singleton(SensitiveMetadataRedactor::class);
        $this->app->singleton(AssignmentConflictQuery::class);
        $this->app->singleton(AssignmentDecisionMaker::class);
        $this->app->singleton(AssignmentLockStore::class);
        $this->app->singleton(ActorIdentityResolver::class);
        $this->app->singleton(ClockInterface::class, SystemClock::class);
        $this->app->singleton(SubjectGuardResolver::class);
        $this->app->singleton(RoleInputResolver::class);
        $this->app->singleton(StrictMutationTransaction::class);
        $this->app->singleton(
            MutationReasonValidator::class,
            static function (Application $app): MutationReasonValidator {
                $required = $app->make('config')->get(
                    'gatetrail.assignments.require_reason',
                    true,
                );

                if (! is_bool($required)) {
                    throw new \InvalidArgumentException(
                        'GateTrail assignments.require_reason must be a boolean.',
                    );
                }

                return new MutationReasonValidator($required);
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
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/gatetrail.php' => config_path('gatetrail.php'),
        ], 'gatetrail-config');

        if (config('gatetrail.database.provisioning') !== 'package') {
            return;
        }

        $this->loadMigrationsFrom(
            __DIR__.'/../database/migrations',
        );
    }
}
