<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Security;

use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Events\QueryExecuted;

final class MutationInputBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--database' => 'testing',
        ])->assertExitCode(0);
    }

    public function test_sql_shaped_permission_name_is_bound_and_persisted_as_data(): void
    {
        $payload = "reports.view'); DROP TABLE acl_permissions; --";
        $sql = [];

        $this->app['db']->connection('testing')->listen(
            static function (QueryExecuted $event) use (&$sql): void {
                $sql[] = $event->sql;
            },
        );

        $result = $this->app->make(PermissionManager::class)->create(
            name: $payload,
            guard: 'web',
            actor: SystemActor::named('security-regression'),
            reason: 'Prove hostile input remains bound data',
        );

        $this->assertSame($payload, $result->resource->getAttribute('name'));
        $this->assertTrue($this->app['db']->getSchemaBuilder()->hasTable(
            'acl_permissions',
        ));
        $this->assertSame(
            $payload,
            Permission::query()->sole()->getAttribute('name'),
        );

        foreach ($sql as $statement) {
            $this->assertStringNotContainsString($payload, $statement);
        }
    }
}
