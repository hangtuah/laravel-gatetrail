<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Tests\Concerns;

use HangTuah\GateTrail\Contracts\AssignmentManager;
use HangTuah\GateTrail\Contracts\PermissionManager;
use HangTuah\GateTrail\Contracts\RoleManager;
use HangTuah\GateTrail\MutationResult;
use HangTuah\GateTrail\PermissionChanges;
use HangTuah\GateTrail\RoleChanges;
use HangTuah\GateTrail\SystemActor;
use HangTuah\GateTrail\Tests\Fixtures\TestSubject;
use Illuminate\Database\Eloquent\Model;

/**
 * Drives every managed mutation once through the public manager contracts.
 *
 * Audit-mode suites share this so "all fourteen handlers" has one definition:
 * a fifteenth mutation has to be added here before any mode can claim to
 * cover it.
 */
trait ExercisesEveryMutation
{
    /**
     * The one audit event each of the fourteen handlers produces, in the
     * order {@see self::everyMutation()} exercises them.
     */
    private const EVENTS = [
        'role.created',
        'role.updated',
        'permission.created',
        'permission.updated',
        'role.assigned',
        'role.revoked',
        'permission.granted_to_subject',
        'permission.revoked_from_subject',
        'permission.granted_to_role',
        'permission.revoked_from_role',
        'permission.deactivated',
        'permission.restored',
        'role.deactivated',
        'role.restored',
    ];

    /**
     * @return array<string, MutationResult<Model>>
     */
    private function everyMutation(): array
    {
        $actor = SystemActor::named('audit-suite');
        $roles = app(RoleManager::class);
        $permissions = app(PermissionManager::class);
        $assignments = app(AssignmentManager::class);
        $subject = TestSubject::query()->create(['name' => 'Employee']);
        $results = [];

        $results['role.created'] = $roles->create(
            'reviewer',
            'web',
            $actor,
            reason: 'Introduce reviewer',
            description: 'Reviews documents.',
            metadata: ['ticket' => 'SEC-1'],
        );
        $role = $results['role.created']->resource;

        $results['role.updated'] = $roles->update(
            $role,
            RoleChanges::description('Reviews submissions.'),
            $actor,
            reason: 'Clarify purpose',
        );

        $results['permission.created'] = $permissions->create(
            'documents.approve',
            'web',
            $actor,
            reason: 'Introduce approval',
            description: 'Approve documents.',
        );
        $permission = $results['permission.created']->resource;

        $results['permission.updated'] = $permissions->update(
            $permission,
            PermissionChanges::description('Approve all documents.'),
            $actor,
            reason: 'Clarify purpose',
        );

        $results['role.assigned'] = $assignments->assignRole(
            subject: $subject,
            role: $role,
            actor: $actor,
            reason: 'Assigned by administrator',
        );
        $results['role.revoked'] = $assignments->revokeRole(
            assignment: $results['role.assigned']->resource,
            actor: $actor,
            reason: 'No longer required',
        );

        $results['permission.granted_to_subject'] = $assignments
            ->grantDirectPermission(
                subject: $subject,
                permission: $permission,
                actor: $actor,
                reason: 'Granted by administrator',
            );
        $results['permission.revoked_from_subject'] = $assignments
            ->revokeDirectPermission(
                assignment: $results['permission.granted_to_subject']
                    ->resource,
                actor: $actor,
                reason: 'No longer required',
            );

        $results['permission.granted_to_role'] = $assignments
            ->grantPermissionToRole(
                role: $role,
                permission: $permission,
                actor: $actor,
                reason: 'Granted by administrator',
            );
        $results['permission.revoked_from_role'] = $assignments
            ->revokePermissionFromRole(
                assignment: $results['permission.granted_to_role']->resource,
                actor: $actor,
                reason: 'No longer required',
            );

        $results['permission.deactivated'] = $permissions->deactivate(
            $permission,
            $actor,
            reason: 'Superseded',
        );
        $results['permission.restored'] = $permissions->restore(
            $permission,
            $actor,
            reason: 'Reinstated',
        );

        $results['role.deactivated'] = $roles->deactivate(
            $role,
            $actor,
            reason: 'Superseded',
        );
        $results['role.restored'] = $roles->restore(
            $role,
            $actor,
            reason: 'Reinstated',
        );

        return $results;
    }
}
