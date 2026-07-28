<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Ddl\DdlArtifactGenerator;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\TestCase;

final class MySqlDdlArtifactTest extends TestCase
{
    public function test_install_artifact_matches_the_canonical_compiler_output(): void
    {
        $artifact = $this->artifact('install.sql');
        $expected = $this->generator()->install(
            'mysql',
            $this->app->make(SchemaManifest::class),
            $this->app->make(SchemaContext::class),
        );

        $this->assertSame($expected, $artifact);
        $this->assertSame(7, substr_count($artifact, 'CREATE TABLE'));
        $this->assertLessThan(
            strpos($artifact, 'CREATE TABLE `acl_role_assignments`'),
            strpos($artifact, 'CREATE TABLE `acl_roles`'),
        );
        $this->assertStringNotContainsString('CASCADE', $artifact);
    }

    public function test_rollback_artifact_matches_reverse_dependency_order(): void
    {
        $artifact = $this->artifact('rollback.sql');
        $expected = $this->generator()->rollback(
            'mysql',
            $this->app->make(SchemaManifest::class),
            $this->app->make(SchemaContext::class),
        );

        $this->assertSame($expected, $artifact);
        $this->assertSame(7, substr_count($artifact, 'DROP TABLE'));
        $this->assertLessThan(
            strpos($artifact, 'DROP TABLE `acl_roles`'),
            strpos($artifact, 'DROP TABLE `acl_access_audits`'),
        );
    }

    private function artifact(string $filename): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2).'/database/ddl/mysql/0001/'.$filename,
        );

        $this->assertIsString($contents);

        return $contents;
    }

    private function generator(): DdlArtifactGenerator
    {
        return $this->app->make(DdlArtifactGenerator::class);
    }
}
