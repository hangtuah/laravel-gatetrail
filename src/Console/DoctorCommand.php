<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Console;

use HangTuah\GateTrail\Diagnostics\DiagnosticFinding;
use HangTuah\GateTrail\Diagnostics\DiagnosticReport;
use HangTuah\GateTrail\Diagnostics\DiagnosticSeverity;
use HangTuah\GateTrail\Diagnostics\GateTrailDoctor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;

/**
 * @internal
 */
final class DoctorCommand extends Command
{
    protected $signature = 'gatetrail:doctor
        {--json : Emit stable machine-readable JSON output}';

    protected $description = 'Inspect GateTrail schema and data without modifying them';

    public function __construct(
        private readonly Application $app,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->app
            ->make(GateTrailDoctor::class)
            ->run();

        if ((bool) $this->option('json')) {
            $this->line($this->json($report));

            return $report->exitCode();
        }

        $this->components->info('GateTrail diagnostic report');

        foreach ($report->coverage as $check => $complete) {
            $this->line(sprintf(
                '  [%s] %s',
                $complete ? 'complete' : 'incomplete',
                $check,
            ));
        }

        if ($report->findings === []) {
            $this->components->info('No observable inconsistency was found.');
        } else {
            foreach ($report->findings as $finding) {
                $this->renderFinding($finding);
            }
        }

        if (! $report->isComplete()) {
            $this->components->warn(
                'Inspection was incomplete; this result is not clean.',
            );
        } elseif ($report->hasErrors()) {
            $this->components->error(
                'GateTrail found one or more data or schema errors.',
            );
        }

        $this->components->warn(self::limitation());

        return $report->exitCode();
    }

    private function renderFinding(DiagnosticFinding $finding): void
    {
        $reference = $finding->reference === null
            ? ''
            : ' reference='.$finding->reference;
        $message = sprintf(
            '[%s] %s: %s%s',
            $finding->severity->value,
            $finding->code,
            $finding->message,
            $reference,
        );

        match ($finding->severity) {
            DiagnosticSeverity::Error => $this->components->error($message),
            DiagnosticSeverity::Warning => $this->components->warn($message),
            DiagnosticSeverity::Informational => $this->components->info($message),
        };
    }

    private function json(DiagnosticReport $report): string
    {
        return json_encode([
            'command' => 'gatetrail:doctor',
            'status' => $report->status(),
            'complete' => $report->isComplete(),
            'exit_code' => $report->exitCode(),
            'coverage' => $report->coverage,
            'counts' => $report->counts(),
            'findings' => array_map(
                static fn (DiagnosticFinding $finding): array => [
                    'code' => $finding->code,
                    'severity' => $finding->severity->value,
                    'message' => $finding->message,
                    'reference' => $finding->reference,
                ],
                $report->findings,
            ),
            'limitation' => self::limitation(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function limitation(): string
    {
        return 'A clean result cannot prove the absence of direct database writes or missing audit history.';
    }
}
