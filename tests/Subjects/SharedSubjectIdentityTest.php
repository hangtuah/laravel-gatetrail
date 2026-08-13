<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Subjects;

use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Events\MutationCommitted;
use HangTuah\GateTrail\Exceptions\InvalidSubject;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\MutationOutcome;
use HangTuah\GateTrail\Mutations\Handlers\AssignRoleHandler;
use HangTuah\GateTrail\Mutations\Handlers\GrantDirectPermissionHandler;
use HangTuah\GateTrail\Subjects\SubjectIdentityResolver;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\ArbitraryKeySubject;
use HangTuah\GateTrail\Tests\Fixtures\EmptyMorphSubject;
use HangTuah\GateTrail\Tests\Fixtures\StringKeyedSubject;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use HangTuah\GateTrail\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Proves the two mutation paths and the shared component agree.
 *
 * Before this increment `AssignRoleHandler` and `GrantDirectPermissionHandler`
 * each carried a private `subjectIdentity()` with *different* validation, so
 * GateTrail could accept a subject for one mutation that the other rejected.
 * Every case below is asserted against both paths from one table.
 */
final class SharedSubjectIdentityTest extends TestCase
{
    /**
     * The one rejection table. A subject rejected here can be neither assigned
     * a role nor granted a permission, so no subject exists that one path can
     * write and the other cannot.
     *
     * @return array<string, array{mixed}>
     */
    public static function rejectedIdentifiers(): array
    {
        return [
            'null key' => [null],
            'empty string' => [''],
            'negative string' => ['-1'],
            'signed string' => ['+1'],
            'leading space' => [' 1'],
            'trailing space' => ['1 '],
            'decimal notation' => ['1.0'],
            'exponent notation' => ['1e3'],
            'hexadecimal notation' => ['0x1f'],
            'uuid' => ['0193c4a1-6f2e-7a3b-9c4d-5e6f70819a2b'],
            'ulid' => ['01JBQZ9YV8K3M2N4P5R6S7T8V9'],
            'above php int max' => ['9223372036854775808'],
            'float key' => [1.0],
            'boolean key' => [true],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function mutations(): array
    {
        return [
            'assignRole' => ['assignRole'],
            'grantDirectPermission' => ['grantDirectPermission'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testing'])
            ->assertExitCode(0);

        Schema::create('string_keyed_subjects', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
        });

        Role::query()->create([
            'name' => 'reviewer',
            'guard_name' => 'web',
            'description' => null,
        ]);
        Permission::query()->create([
            'name' => 'documents.approve',
            'guard_name' => 'web',
            'description' => null,
        ]);
    }

    public function test_both_handlers_depend_on_the_shared_resolver(): void
    {
        foreach ([AssignRoleHandler::class, GrantDirectPermissionHandler::class] as $handler) {
            $types = array_map(
                static function (\ReflectionParameter $parameter): ?string {
                    $type = $parameter->getType();

                    return $type instanceof ReflectionNamedType
                        ? $type->getName()
                        : null;
                },
                (new ReflectionMethod($handler, '__construct'))->getParameters(),
            );

            $this->assertContains(
                SubjectIdentityResolver::class,
                $types,
                sprintf('[%s] must use the shared resolver.', $handler),
            );
        }
    }

    /**
     * The duplicated rule is gone, not merely bypassed.
     */
    public function test_neither_handler_keeps_a_private_subject_identity_copy(): void
    {
        foreach ([AssignRoleHandler::class, GrantDirectPermissionHandler::class] as $handler) {
            $this->assertFalse(
                method_exists($handler, 'subjectIdentity'),
                sprintf('[%s] must not keep its own identity rule.', $handler),
            );
        }
    }

    public function test_both_paths_persist_the_identical_canonical_identity(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);

        $assignment = $this->manager()->assignRole(
            $subject, 'reviewer', SystemActor::named('suite'), 'Granted for review',
        );
        $grant = $this->manager()->grantDirectPermission(
            $subject, 'documents.approve', SystemActor::named('suite'), 'Granted for review',
        );

        $identity = app(SubjectIdentityResolver::class)->resolve($subject);

        foreach ([$assignment, $grant] as $result) {
            $this->assertSame(MutationOutcome::Created, $result->outcome);
            $this->assertSame(
                $identity->type,
                $result->resource->getAttribute('subject_type'),
            );
            $this->assertSame(
                $identity->id,
                (int) $result->resource->getAttribute('subject_id'),
            );
        }
    }

    /**
     * A leading-zero key is canonicalized once, so both paths write the same
     * `subject_id` and a later read cannot miss one of them.
     */
    public function test_both_paths_canonicalize_a_leading_zero_string_key(): void
    {
        $subject = StringKeyedSubject::query()->create([
            'id' => '007',
            'name' => 'Auditor',
        ]);

        $assignment = $this->manager()->assignRole(
            $subject, 'reviewer', SystemActor::named('suite'), 'Granted for review',
        );
        $grant = $this->manager()->grantDirectPermission(
            $subject, 'documents.approve', SystemActor::named('suite'), 'Granted for review',
        );

        $this->assertSame(7, (int) $assignment->resource->getAttribute('subject_id'));
        $this->assertSame(7, (int) $grant->resource->getAttribute('subject_id'));
        $this->assertSame(
            $assignment->resource->getAttribute('subject_id'),
            $grant->resource->getAttribute('subject_id'),
        );
    }

    #[DataProvider('rejectedIdentifiers')]
    public function test_both_paths_reject_the_same_identifiers(mixed $key): void
    {
        $subject = ArbitraryKeySubject::persistedWith($key);

        $this->assertRejectedByBothPaths($subject);
    }

    public function test_both_paths_reject_an_unsaved_subject(): void
    {
        $this->assertRejectedByBothPaths(
            new TestSubject(['name' => 'Reviewer']),
            InvalidSubject::REASON_NOT_PERSISTED,
        );
    }

    public function test_both_paths_reject_a_dirty_primary_key(): void
    {
        $subject = TestSubject::query()->create(['name' => 'Reviewer']);
        $other = TestSubject::query()->create(['name' => 'Administrator']);
        $subject->id = $other->getKey();

        $this->assertRejectedByBothPaths(
            $subject,
            InvalidSubject::REASON_MUTATED_IDENTIFIER,
        );
    }

    /**
     * The behaviour change this increment makes deliberately: the direct
     * permission path previously accepted an empty morph type and wrote it
     * into `subject_type`, while the role path rejected it.
     */
    public function test_both_paths_now_reject_an_empty_morph_type(): void
    {
        $this->assertRejectedByBothPaths(
            EmptyMorphSubject::persistedWith(''),
            InvalidSubject::REASON_INVALID_MORPH_TYPE,
        );
    }

    /**
     * Failure must happen before the transaction, the coordination lock, the
     * assignment row, the audit row and the event.
     */
    #[DataProvider('mutations')]
    public function test_a_rejected_subject_leaves_no_trace(string $mutation): void
    {
        Event::fake([MutationCommitted::class]);

        $subject = ArbitraryKeySubject::persistedWith(
            '0193c4a1-6f2e-7a3b-9c4d-5e6f70819a2b',
        );

        $statements = 0;
        $this->app['db']->listen(function () use (&$statements): void {
            $statements++;
        });

        try {
            $this->invoke($mutation, $subject);
            $this->fail('Expected InvalidSubject.');
        } catch (InvalidSubject) {
            // asserted below
        }

        // Empty tables alone would also be consistent with a rolled-back
        // transaction. Zero statements proves the rejection happened before
        // any database work was attempted at all.
        $this->assertSame(
            0,
            $statements,
            'A rejected subject must not reach the database.',
        );

        foreach ([
            'acl_role_assignments',
            'acl_permission_assignments',
            'acl_assignment_locks',
            'acl_access_audits',
        ] as $table) {
            $this->assertSame(
                0,
                $this->app['db']->table($table)->count(),
                sprintf('[%s] must be untouched by a rejected subject.', $table),
            );
        }

        Event::assertNotDispatched(MutationCommitted::class);
        $this->assertSame(0, $this->app['db']->transactionLevel());
    }

    /**
     * The shared rule is the same object the mutation paths use, so a subject
     * either resolves for all three or for none.
     */
    #[DataProvider('rejectedIdentifiers')]
    public function test_the_resolver_and_both_paths_agree(mixed $key): void
    {
        $subject = ArbitraryKeySubject::persistedWith($key);

        $this->expectException(InvalidSubject::class);

        app(SubjectIdentityResolver::class)->resolve($subject);
    }

    public function test_public_manager_signatures_are_unchanged(): void
    {
        foreach (['assignRole' => 1, 'grantDirectPermission' => 1] as $method => $subjectPosition) {
            $parameters = (new ReflectionMethod(AssignmentManager::class, $method))
                ->getParameters();

            $this->assertCount(6, $parameters);
            $this->assertSame('subject', $parameters[0]->getName());

            $type = $parameters[0]->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame(Model::class, $type->getName());
            $this->assertSame($subjectPosition, $parameters[0]->getPosition() + 1);
        }
    }

    public function test_a_successful_mutation_still_produces_its_audit_and_event(): void
    {
        Event::fake([MutationCommitted::class]);

        $subject = TestSubject::query()->create(['name' => 'Reviewer']);

        $result = $this->manager()->assignRole(
            $subject, 'reviewer', SystemActor::named('suite'), 'Granted for review',
        );

        $this->assertSame(MutationOutcome::Created, $result->outcome);
        $this->assertNotNull($result->auditId);
        $this->assertSame(1, $this->app['db']->table('acl_access_audits')->count());

        Event::assertDispatched(MutationCommitted::class);
    }

    private function assertRejectedByBothPaths(
        Model $subject,
        ?string $reason = null,
    ): void {
        foreach (array_keys(self::mutations()) as $mutation) {
            try {
                $this->invoke($mutation, $subject);
                $this->fail(sprintf('[%s] must reject this subject.', $mutation));
            } catch (InvalidSubject $exception) {
                if ($reason !== null) {
                    $this->assertSame(
                        $reason,
                        $exception->reason,
                        sprintf('[%s] must report the shared reason.', $mutation),
                    );
                }

                $this->assertNull($exception->getPrevious());
            }
        }
    }

    private function invoke(string $mutation, Model $subject): void
    {
        $manager = $this->manager();
        $actor = SystemActor::named('suite');

        $mutation === 'assignRole'
            ? $manager->assignRole($subject, 'reviewer', $actor, 'Granted for review')
            : $manager->grantDirectPermission(
                $subject, 'documents.approve', $actor, 'Granted for review',
            );
    }

    private function manager(): AssignmentManager
    {
        return app(AssignmentManager::class);
    }
}
