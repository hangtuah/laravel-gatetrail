<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Database;

use HangTuah\GateTrail\Database\Schema\Ddl\DdlArtifactGenerator;
use HangTuah\GateTrail\Database\Schema\IdentifierValidator;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Tests\TestCase;

final class OracleDdlArtifactTest extends TestCase
{
    public function test_identity_artifacts_match_the_canonical_compiler_output(): void
    {
        $context = $this->context('identity');
        $install = $this->artifact('identity', 'install.sql');
        $rollback = $this->artifact('identity', 'rollback.sql');

        $this->assertSame(
            $this->generator()->install(
                'oracle',
                $this->manifest(),
                $context,
                'identity',
            ),
            $install,
        );
        $this->assertSame(
            $this->generator()->rollback(
                'oracle',
                $this->manifest(),
                $context,
                'identity',
            ),
            $rollback,
        );
        $this->assertStringContainsString(
            '-- Generated-key strategy: identity',
            $install,
        );
        $this->assertSame(7, substr_count($install, 'CREATE TABLE'));
        $this->assertSame(6, substr_count($install, ' AS IDENTITY'));
        $this->assertStringNotContainsString('CREATE SEQUENCE', $install);
        $this->assertStringNotContainsString('CREATE TRIGGER', $install);
        $this->assertSame(7, substr_count($rollback, 'DROP TABLE'));
    }

    public function test_sequence_trigger_artifacts_match_the_canonical_compiler_output(): void
    {
        $context = $this->context('sequence_trigger');
        $install = $this->artifact('sequence-trigger', 'install.sql');
        $rollback = $this->artifact('sequence-trigger', 'rollback.sql');

        $this->assertSame(
            $this->generator()->install(
                'oracle',
                $this->manifest(),
                $context,
                'sequence_trigger',
            ),
            $install,
        );
        $this->assertSame(
            $this->generator()->rollback(
                'oracle',
                $this->manifest(),
                $context,
                'sequence_trigger',
            ),
            $rollback,
        );
        $this->assertStringContainsString(
            '-- Generated-key strategy: sequence_trigger',
            $install,
        );
        $this->assertSame(7, substr_count($install, 'CREATE TABLE'));
        $this->assertSame(6, substr_count($install, 'CREATE SEQUENCE'));
        $this->assertSame(6, substr_count($install, 'CREATE TRIGGER'));
        $this->assertSame(6, substr_count($rollback, 'DROP TRIGGER'));
        $this->assertSame(7, substr_count($rollback, 'DROP TABLE'));
        $this->assertSame(6, substr_count($rollback, 'DROP SEQUENCE'));
        $this->assertStringContainsString(
            "IF :NEW.id IS NULL THEN\n"
            .'        :NEW.id := acl_roles_seq.NEXTVAL;',
            $install,
        );
    }

    public function test_install_artifacts_preserve_dependency_order_and_fail_loudly(): void
    {
        foreach (['identity', 'sequence-trigger'] as $strategy) {
            $install = $this->artifact($strategy, 'install.sql');

            $this->assertLessThan(
                strpos($install, 'CREATE TABLE acl_role_assignments'),
                strpos($install, 'CREATE TABLE acl_roles'),
            );
            $this->assertLessThan(
                strpos($install, 'CREATE TABLE acl_permission_assignments'),
                strpos($install, 'CREATE TABLE acl_permissions'),
            );
            $this->assertStringNotContainsString('IF NOT EXISTS', $install);
            $this->assertStringNotContainsString('OR REPLACE', $install);
            $this->assertStringNotContainsString('CASCADE', $install);
        }
    }

    public function test_sequence_trigger_rollback_follows_physical_dependencies(): void
    {
        $rollback = $this->artifact('sequence-trigger', 'rollback.sql');
        $lastTrigger = strrpos($rollback, 'DROP TRIGGER');
        $firstTable = strpos($rollback, 'DROP TABLE');
        $lastTable = strrpos($rollback, 'DROP TABLE');
        $firstSequence = strpos($rollback, 'DROP SEQUENCE');

        $this->assertIsInt($lastTrigger);
        $this->assertIsInt($firstTable);
        $this->assertIsInt($lastTable);
        $this->assertIsInt($firstSequence);
        $this->assertLessThan($firstTable, $lastTrigger);
        $this->assertLessThan($firstSequence, $lastTable);
        $this->assertLessThan(
            strpos($rollback, 'DROP TABLE acl_roles'),
            strpos($rollback, 'DROP TABLE acl_access_audits'),
        );
    }

    private function artifact(string $strategy, string $filename): string
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2)
            .'/database/ddl/oracle/0001/'
            .$strategy
            .'/'
            .$filename,
        );

        $this->assertIsString($contents);

        return $contents;
    }

    private function context(string $strategy): SchemaContext
    {
        $config = require dirname(__DIR__, 2).'/config/gatetrail.php';
        $config['oracle']['generated_keys'] = $strategy;

        return new SchemaContext(
            $config,
            new IdentifierValidator,
            $this->manifest(),
        );
    }

    private function manifest(): SchemaManifest
    {
        return $this->app->make(SchemaManifest::class);
    }

    private function generator(): DdlArtifactGenerator
    {
        return $this->app->make(DdlArtifactGenerator::class);
    }
}
