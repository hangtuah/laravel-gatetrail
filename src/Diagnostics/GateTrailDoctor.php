<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Diagnostics;

use HangTuah\GateTrail\Database\Schema\Inspection\InspectionSeverity;
use HangTuah\GateTrail\Database\Schema\Inspection\SchemaInspectorFactory;
use HangTuah\GateTrail\Database\Schema\Manifest\SchemaManifest;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Exceptions\SchemaInspectorUnavailable;
use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * @internal
 */
final readonly class GateTrailDoctor
{
    public function __construct(
        private DatabaseManager $database,
        private SchemaContext $schema,
        private SchemaManifest $manifest,
        private SchemaInspectorFactory $inspectors,
        private AssignmentDataScanner $data,
    ) {}

    public function run(): DiagnosticReport
    {
        $data = $this->data->scan();
        $findings = $data->findings;
        $coverage = $data->coverage;
        $coverage['schema.physical'] = true;

        try {
            $connection = $this->database->connection(
                $this->schema->connectionName(),
            );
            $inspection = $this->inspectors
                ->forDriver($connection->getDriverName(), $connection)
                ->inspect($this->manifest, $this->schema);

            foreach ($inspection->issues as $issue) {
                $findings[] = new DiagnosticFinding(
                    'schema.'.$issue->code,
                    $issue->severity === InspectionSeverity::Error
                        ? DiagnosticSeverity::Error
                        : DiagnosticSeverity::Warning,
                    $issue->message,
                    DiagnosticReference::for(
                        'schema.'.$issue->code,
                        $issue->object,
                    ),
                );
            }
        } catch (SchemaInspectorUnavailable) {
            $coverage['schema.physical'] = false;
            $findings[] = new DiagnosticFinding(
                'schema.inspector_unavailable',
                DiagnosticSeverity::Warning,
                'Physical schema inspection is unavailable for the configured database driver.',
            );
        } catch (Throwable) {
            $coverage['schema.physical'] = false;
            $findings[] = new DiagnosticFinding(
                'schema.inspection_failed',
                DiagnosticSeverity::Warning,
                'Physical schema inspection could not be completed.',
            );
        }

        return new DiagnosticReport($findings, $coverage);
    }
}
