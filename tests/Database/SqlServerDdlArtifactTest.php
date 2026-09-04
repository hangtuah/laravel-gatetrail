<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Ddl\DdlArtifactGenerator;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\TestCase;

final class SqlServerDdlArtifactTest extends TestCase
{
    public function test_install_artifact_matches_the_canonical_compiler_output(): void
    {
        $artifact = $this->artifact('install.sql');
        $expected = $this->generator()->install(
            'sqlsrv',
            $this->app->make(SchemaManifest::class),
            $this->app->make(SchemaContext::class),
        );

        $this->assertSame($expected, $artifact);
        $this->assertSame(7, substr_count($artifact, 'CREATE TABLE'));
        $this->assertSame(15, substr_count($artifact, 'CREATE INDEX'));
        $this->assertLessThan(
            strpos($artifact, 'CREATE TABLE [acl_role_assignments]'),
            strpos($artifact, 'CREATE TABLE [acl_roles]'),
        );
        $this->assertLessThan(
            strpos($artifact, 'CREATE TABLE [acl_permission_assignments]'),
            strpos($artifact, 'CREATE TABLE [acl_permissions]'),
        );
    }

    public function test_rollback_artifact_matches_reverse_dependency_order(): void
    {
        $artifact = $this->artifact('rollback.sql');
        $expected = $this->generator()->rollback(
            'sqlsrv',
            $this->app->make(SchemaManifest::class),
            $this->app->make(SchemaContext::class),
        );

        $this->assertSame($expected, $artifact);
        $this->assertSame(7, substr_count($artifact, 'DROP TABLE'));
        $this->assertLessThan(
            strpos($artifact, 'DROP TABLE [acl_roles]'),
            strpos($artifact, 'DROP TABLE [acl_access_audits]'),
        );
        $this->assertLessThan(
            strpos($artifact, 'DROP TABLE [acl_permissions]'),
            strpos($artifact, 'DROP TABLE [acl_permission_assignments]'),
        );
    }

    public function test_both_artifacts_carry_the_release_driver_and_direction_header(): void
    {
        foreach ([
            'install.sql' => 'install',
            'rollback.sql' => 'rollback',
        ] as $filename => $direction) {
            $artifact = $this->artifact($filename);

            $this->assertStringStartsWith(
                "-- GateTrail schema release 0001\n"
                ."-- Database driver: sqlsrv\n"
                .'-- Direction: '.$direction."\n"
                ."-- Generated from the canonical schema manifest; do not edit manually.\n",
                $artifact,
            );
            $this->assertStringNotContainsString(
                'Generated-key strategy',
                $artifact,
            );
        }
    }

    /**
     * The committed artifacts are unqualified and unprefixed, so nothing about
     * one deployment's database, schema, login or host leaks into a file every
     * consumer receives.
     */
    public function test_artifacts_carry_no_environment_specific_identifier(): void
    {
        foreach (['install.sql', 'rollback.sql'] as $filename) {
            $artifact = $this->artifact($filename);

            foreach ([
                'IF NOT EXISTS',
                'IF EXISTS',
                'CASCADE',
                'SET NULL',
                'SET DEFAULT',
                'USE ',
                'CREATE DATABASE',
                'CREATE SCHEMA',
                'ALTER AUTHORIZATION',
                'localhost',
                '127.0.0.1',
                'sa',
                'PASSWORD',
                'gatetrail_test',
                'master',
                'dbo.',
            ] as $rejected) {
                $this->assertStringNotContainsString(
                    $rejected,
                    $artifact,
                    sprintf('%s must not contain [%s].', $filename, $rejected),
                );
            }

            foreach (preg_split('/\R/', $artifact) ?: [] as $line) {
                $this->assertNotSame('GO', trim($line));
            }
        }
    }

    /**
     * Regenerating must reproduce every committed artifact byte-for-byte,
     * including the three that already existed, so this increment cannot have
     * quietly changed another driver's output.
     */
    public function test_generation_is_idempotent_for_every_committed_artifact(): void
    {
        $manifest = $this->app->make(SchemaManifest::class);
        $context = $this->app->make(SchemaContext::class);
        $generator = $this->generator();

        foreach ([
            'mysql/0001' => 'mysql',
            'postgresql/0001' => 'pgsql',
            'sqlserver/0001' => 'sqlsrv',
        ] as $directory => $driver) {
            foreach (['install', 'rollback'] as $direction) {
                $path = sprintf(
                    '%s/database/ddl/%s/%s.sql',
                    dirname(__DIR__, 2),
                    $directory,
                    $direction,
                );
                $committed = file_get_contents($path);

                $this->assertIsString($committed, $path);

                $regenerated = $direction === 'install'
                    ? $generator->install($driver, $manifest, $context)
                    : $generator->rollback($driver, $manifest, $context);

                $this->assertSame($regenerated, $committed, $path);
                $this->assertSame(
                    $regenerated,
                    $direction === 'install'
                        ? $generator->install($driver, $manifest, $context)
                        : $generator->rollback($driver, $manifest, $context),
                    $path.' is not idempotent',
                );
            }
        }
    }

    private function artifact(string $filename): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2).'/database/ddl/sqlserver/0001/'.$filename,
        );

        $this->assertIsString($contents);

        return $contents;
    }

    private function generator(): DdlArtifactGenerator
    {
        return $this->app->make(DdlArtifactGenerator::class);
    }
}
