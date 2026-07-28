<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Integration\Oracle;

final class InspectorDriftTest extends OracleIntegrationTestCase
{
    public function test_a_missing_index_is_reported_without_repair(): void
    {
        $this->executeArtifact('install');
        $this->connection()->statement(
            'DROP INDEX acl_ra_subject_ix',
        );

        $inspection = $this->inspectSchema();

        $this->assertContains(
            'missing_index',
            $this->inspectionCodes($inspection),
        );
        $this->assertFalse($inspection->passes());
        $this->assertFalse(
            $this->objectExists(
                'user_indexes',
                'index_name',
                'acl_ra_subject_ix',
            ),
        );
    }
}
