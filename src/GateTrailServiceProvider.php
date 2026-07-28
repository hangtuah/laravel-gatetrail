<?php

declare(strict_types=1);

namespace HangTuah\GateTrail;

use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaAdapterFactory;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class GateTrailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/gatetrail.php',
            'gatetrail',
        );

        $this->app->singleton(IdentifierValidator::class);
        $this->app->singleton(SchemaAdapterFactory::class);
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
