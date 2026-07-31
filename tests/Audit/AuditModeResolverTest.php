<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Audit;

use HangTuah\GateTrail\Audit\AuditMode;
use HangTuah\GateTrail\Audit\AuditModeResolver;
use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\Events\AuditPersistenceFailed;
use HangTuah\GateTrail\Exceptions\InvalidAuditConfiguration;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\RecordingLogger;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;
use stdClass;

final class AuditModeResolverTest extends TestCase
{
    /**
     * @var list<AuditPersistenceFailed>
     */
    private array $failures = [];

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);

        $this->failures = [];
        $this->logger = new RecordingLogger;
        $this->app->instance(LoggerInterface::class, $this->logger);

        Event::listen(
            AuditPersistenceFailed::class,
            function (AuditPersistenceFailed $event): void {
                $this->failures[] = $event;
            },
        );
    }

    public function test_only_real_booleans_select_a_mode(): void
    {
        $this->assertSame(
            AuditMode::Strict,
            $this->resolve(enabled: true, strict: true),
        );
        $this->assertSame(
            AuditMode::NonStrict,
            $this->resolve(enabled: true, strict: false),
        );
        $this->assertSame(
            AuditMode::Disabled,
            $this->resolve(enabled: false, strict: true),
        );
        $this->assertSame(
            AuditMode::Disabled,
            $this->resolve(enabled: false, strict: false),
        );
    }

    public function test_a_disabled_mode_is_still_unimplemented(): void
    {
        // Guards this increment's scope: recognising Disabled is not the same
        // as supporting it.
        $this->assertSame(
            AuditMode::Disabled,
            $this->resolve(enabled: false, strict: false),
        );
    }

    /**
     * Every value a consumer could plausibly write that is not a boolean.
     *
     * @return list<array{mixed, string}>
     */
    public static function malformedFlags(): array
    {
        return [
            'null' => [null, 'null'],
            'string true' => ['true', '"true"'],
            'string false' => ['false', '"false"'],
            'empty string' => ['', 'empty string'],
            'integer zero' => [0, '0'],
            'integer one' => [1, '1'],
            'float' => [1.0, '1.0'],
            'list' => [[true], 'array'],
            'map' => [['strict' => true], 'map'],
            'object' => [new stdClass, 'object'],
        ];
    }

    public function test_a_malformed_strict_flag_never_selects_non_strict(): void
    {
        foreach (self::malformedFlags() as $label => [$value, $_]) {
            config()->set('gatetrail.audit.enabled', true);
            config()->set('gatetrail.audit.strict', $value);

            try {
                $this->resolver()->resolve();

                $this->fail("A [$label] strict flag should be rejected.");
            } catch (InvalidAuditConfiguration $exception) {
                $this->assertSame(
                    'gatetrail.audit.strict',
                    $exception->key,
                );
            }
        }
    }

    public function test_a_malformed_enabled_flag_never_selects_a_mode(): void
    {
        foreach (self::malformedFlags() as $label => [$value, $_]) {
            config()->set('gatetrail.audit.enabled', $value);
            config()->set('gatetrail.audit.strict', true);

            try {
                $this->resolver()->resolve();

                $this->fail("A [$label] enabled flag should be rejected.");
            } catch (InvalidAuditConfiguration $exception) {
                $this->assertSame(
                    'gatetrail.audit.enabled',
                    $exception->key,
                );
            }
        }
    }

    public function test_missing_keys_are_rejected_rather_than_defaulted(): void
    {
        config()->set('gatetrail.audit', []);

        try {
            $this->resolver()->resolve();

            $this->fail('A missing enabled flag should be rejected.');
        } catch (InvalidAuditConfiguration $exception) {
            $this->assertSame('gatetrail.audit.enabled', $exception->key);
        }

        config()->set('gatetrail.audit', ['enabled' => true]);

        try {
            $this->resolver()->resolve();

            $this->fail('A missing strict flag should be rejected.');
        } catch (InvalidAuditConfiguration $exception) {
            $this->assertSame('gatetrail.audit.strict', $exception->key);
        }
    }

    public function test_the_exception_never_echoes_the_rejected_value(): void
    {
        config()->set('gatetrail.audit.enabled', true);
        config()->set('gatetrail.audit.strict', 'super-secret-token');

        try {
            $this->resolver()->resolve();

            $this->fail('A malformed strict flag should be rejected.');
        } catch (InvalidAuditConfiguration $exception) {
            $this->assertStringNotContainsString(
                'super-secret-token',
                $exception->getMessage(),
            );
            $this->assertSame(
                'GateTrail configuration [gatetrail.audit.strict] must be a boolean.',
                $exception->getMessage(),
            );
        }
    }

    public function test_a_malformed_flag_mutates_no_table_through_the_public_api(): void
    {
        // The dangerous historical case: a string that is neither `true` nor
        // `false` used to fall through to non-strict auditing.
        config()->set('gatetrail.audit.strict', 'false');
        Role::query()->create([
            'name' => 'existing',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $before = $this->rows();

        try {
            app(RoleManager::class)->create(
                'reviewer',
                'web',
                SystemActor::named('config-suite'),
                reason: 'Introduce reviewer',
            );

            $this->fail('A malformed strict flag should be rejected.');
        } catch (InvalidAuditConfiguration $exception) {
            $this->assertSame('gatetrail.audit.strict', $exception->key);
        }

        $this->assertSame($before, $this->rows());
        $this->assertDatabaseCount('acl_access_audits', 0);
        $this->assertSame([], $this->logger->records);
        $this->assertSame([], $this->failures);
    }

    public function test_a_malformed_flag_mutates_no_assignment_table(): void
    {
        config()->set('gatetrail.audit.enabled', 1);
        $role = Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        $subject = TestSubject::query()
            ->create(['name' => 'Employee']);
        $before = $this->rows();

        try {
            app(AssignmentManager::class)
                ->assignRole(
                    subject: $subject,
                    role: $role,
                    actor: SystemActor::named('config-suite'),
                    reason: 'Assigned by administrator',
                );

            $this->fail('A malformed enabled flag should be rejected.');
        } catch (InvalidAuditConfiguration $exception) {
            $this->assertSame('gatetrail.audit.enabled', $exception->key);
        }

        $this->assertSame($before, $this->rows());
        $this->assertSame([], $this->logger->records);
        $this->assertSame([], $this->failures);
    }

    private function resolve(bool $enabled, bool $strict): AuditMode
    {
        config()->set('gatetrail.audit.enabled', $enabled);
        config()->set('gatetrail.audit.strict', $strict);

        return $this->resolver()->resolve();
    }

    private function resolver(): AuditModeResolver
    {
        $this->app->forgetInstance(AuditModeResolver::class);

        return $this->app->make(AuditModeResolver::class);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(): array
    {
        $tables = [
            'acl_roles',
            'acl_permissions',
            'acl_role_assignments',
            'acl_permission_assignments',
            'acl_role_permissions',
            'acl_assignment_locks',
            'acl_access_audits',
        ];
        $rows = [];

        foreach ($tables as $table) {
            $rows[$table] = array_map(
                static fn (object $row): array => (array) $row,
                $this->app['db']->table($table)->orderBy('id')->get()->all(),
            );
        }

        return $rows;
    }
}
