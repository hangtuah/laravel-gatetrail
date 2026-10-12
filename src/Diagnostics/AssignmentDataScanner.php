<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Diagnostics;

use DateTimeInterface;
use HangTuah\GateTrail\Assignments\AssignmentKind;
use HangTuah\GateTrail\Assignments\AssignmentPeriodOverlap;
use HangTuah\GateTrail\Assignments\AssignmentScope;
use HangTuah\GateTrail\Assignments\EffectiveAssignmentPeriod;
use HangTuah\GateTrail\Contracts\PermissionContract;
use HangTuah\GateTrail\Contracts\RoleContract;
use HangTuah\GateTrail\Database\Schema\SchemaContext;
use HangTuah\GateTrail\Guards\SubjectGuardResolver;
use HangTuah\GateTrail\Models\Permission;
use HangTuah\GateTrail\Models\PermissionAssignment;
use HangTuah\GateTrail\Models\Role;
use HangTuah\GateTrail\Models\RoleAssignment;
use HangTuah\GateTrail\Models\RolePermission;
use HangTuah\GateTrail\Support\ModelRegistry;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use stdClass;
use Throwable;

/**
 * Best-effort, read-only scanner for the data invariants accepted by ADR 0009.
 *
 * @internal
 */
final class AssignmentDataScanner
{
    /** @var array<string, DiagnosticFinding> */
    private array $findings = [];

    /** @var array<string, bool> */
    private array $coverage = [];

    /** @var array<string, Model|null> */
    private array $subjects = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly SchemaContext $schema,
        private readonly ModelRegistry $models,
        private readonly AssignmentPeriodOverlap $overlap,
        private readonly SubjectGuardResolver $guards,
    ) {}

    public function scan(): DiagnosticReport
    {
        $this->findings = [];
        $this->subjects = [];
        $this->coverage = [
            'data.assignments' => true,
            'data.coordination' => true,
            'data.definitions' => true,
            'data.subject_guards' => true,
        ];

        try {
            $roleClass = $this->models->role();
            $permissionClass = $this->models->permission();
            $roleAssignmentClass = $this->models->roleAssignment();
            $permissionAssignmentClass = $this->models->permissionAssignment();
            $rolePermissionClass = $this->models->rolePermission();

            /** @var list<Role> $roles */
            $roles = $roleClass::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->get()
                ->all();
            /** @var list<Permission> $permissions */
            $permissions = $permissionClass::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->get()
                ->all();
            /** @var list<RoleAssignment> $roleAssignments */
            $roleAssignments = $roleAssignmentClass::query()
                ->withoutGlobalScopes()
                ->get()
                ->all();
            /** @var list<PermissionAssignment> $permissionAssignments */
            $permissionAssignments = $permissionAssignmentClass::query()
                ->withoutGlobalScopes()
                ->get()
                ->all();
            /** @var list<RolePermission> $rolePermissions */
            $rolePermissions = $rolePermissionClass::query()
                ->withoutGlobalScopes()
                ->get()
                ->all();
            /** @var list<stdClass> $locks */
            $locks = $this->database
                ->connection($this->schema->connectionName())
                ->table($this->schema->table('assignment_locks'))
                ->get([
                    'assignment_kind',
                    'scope_hash',
                    'scope_key',
                ])
                ->all();
        } catch (Throwable) {
            foreach (array_keys($this->coverage) as $check) {
                $this->coverage[$check] = false;
            }

            $this->add(new DiagnosticFinding(
                'data.scan_unavailable',
                DiagnosticSeverity::Warning,
                'GateTrail data inspection could not read every required table.',
            ));

            return $this->report();
        }

        $roleMap = $this->definitionMap($roles);
        $permissionMap = $this->definitionMap($permissions);
        $scopes = [];
        $periods = [];

        foreach ($roleAssignments as $assignment) {
            $scope = $this->roleScope($assignment);

            if ($scope === null) {
                continue;
            }

            $this->inspectAssignment($assignment, $scope, $periods);
            $this->inspectRoleReference($assignment, $scope, $roleMap);
            $scopes[$this->scopeIdentity($scope)] = $scope;
        }

        foreach ($permissionAssignments as $assignment) {
            $scope = $this->permissionScope($assignment);

            if ($scope === null) {
                continue;
            }

            $this->inspectAssignment($assignment, $scope, $periods);
            $this->inspectPermissionReference(
                $assignment,
                $scope,
                $permissionMap,
            );
            $scopes[$this->scopeIdentity($scope)] = $scope;
        }

        foreach ($rolePermissions as $assignment) {
            $scope = $this->rolePermissionScope($assignment);

            if ($scope === null) {
                continue;
            }

            $this->inspectAssignment($assignment, $scope, $periods);
            $this->inspectRolePermissionReferences(
                $assignment,
                $scope,
                $roleMap,
                $permissionMap,
            );
            $scopes[$this->scopeIdentity($scope)] = $scope;
        }

        $this->inspectOverlaps($periods);
        $this->inspectLocks($locks, $scopes);

        return $this->report();
    }

    /** @param list<Model> $definitions
     * @return array<string, Model>
     */
    private function definitionMap(array $definitions): array
    {
        $map = [];

        foreach ($definitions as $definition) {
            $map[(string) $this->modelIdentifier($definition)] = $definition;
        }

        return $map;
    }

    private function roleScope(RoleAssignment $assignment): ?AssignmentScope
    {
        try {
            return AssignmentScope::forRole(
                $this->stringAttribute($assignment, 'subject_type'),
                $this->identifier($assignment, 'subject_id'),
                $this->identifier($assignment, 'role_id'),
            );
        } catch (Throwable) {
            return $this->invalidScope($assignment, AssignmentKind::Role);
        }
    }

    private function permissionScope(
        PermissionAssignment $assignment,
    ): ?AssignmentScope {
        try {
            return AssignmentScope::forPermission(
                $this->stringAttribute($assignment, 'subject_type'),
                $this->identifier($assignment, 'subject_id'),
                $this->identifier($assignment, 'permission_id'),
            );
        } catch (Throwable) {
            return $this->invalidScope($assignment, AssignmentKind::Permission);
        }
    }

    private function rolePermissionScope(
        RolePermission $assignment,
    ): ?AssignmentScope {
        try {
            return AssignmentScope::forRolePermission(
                $this->identifier($assignment, 'role_id'),
                $this->identifier($assignment, 'permission_id'),
            );
        } catch (Throwable) {
            return $this->invalidScope(
                $assignment,
                AssignmentKind::RolePermission,
            );
        }
    }

    private function invalidScope(
        Model $assignment,
        AssignmentKind $kind,
    ): null {
        $this->add(new DiagnosticFinding(
            'assignment.invalid_scope',
            DiagnosticSeverity::Error,
            'An assignment contains an invalid scope identity.',
            DiagnosticReference::for(
                $kind->value,
                $this->modelIdentifier($assignment),
            ),
        ));

        return null;
    }

    /**
     * @param  array<string, list<EffectiveAssignmentPeriod>>  $periods
     */
    private function inspectAssignment(
        RoleAssignment|PermissionAssignment|RolePermission $assignment,
        AssignmentScope $scope,
        array &$periods,
    ): void {
        $reference = $scope->hash();
        $revocation = [
            $assignment->getAttribute('revoked_at'),
            $assignment->getAttribute('revoked_by_type'),
            $assignment->getAttribute('revoked_by_id'),
        ];
        $populated = count(array_filter(
            $revocation,
            static fn (mixed $value): bool => $value !== null,
        ));

        if ($populated !== 0 && $populated !== count($revocation)) {
            $this->add(new DiagnosticFinding(
                'assignment.partial_revocation',
                DiagnosticSeverity::Error,
                'An assignment has a partially populated revocation tuple.',
                $reference,
            ));
        }

        $assignedAt = $assignment->getAttribute('assigned_at');
        $startsAt = $assignment->getAttribute('starts_at');
        $expiresAt = $assignment->getAttribute('expires_at');

        try {
            if (! $assignedAt instanceof DateTimeInterface) {
                throw new \LogicException('Invalid assignment timestamp.');
            }

            $period = EffectiveAssignmentPeriod::fromStored(
                $assignedAt,
                $startsAt instanceof DateTimeInterface ? $startsAt : null,
                $expiresAt instanceof DateTimeInterface ? $expiresAt : null,
            );
        } catch (Throwable) {
            $this->add(new DiagnosticFinding(
                'assignment.invalid_period',
                DiagnosticSeverity::Error,
                'An assignment has invalid period ordering.',
                $reference,
            ));

            return;
        }

        if ($assignment->getAttribute('revoked_at') === null) {
            $periods[$this->scopeIdentity($scope)][] = $period;
        }
    }

    /**
     * @param  array<string, list<EffectiveAssignmentPeriod>>  $periods
     */
    private function inspectOverlaps(array $periods): void
    {
        foreach ($periods as $scope => $candidates) {
            $count = count($candidates);

            for ($left = 0; $left < $count; $left++) {
                for ($right = $left + 1; $right < $count; $right++) {
                    if (! $this->overlap->overlaps(
                        $candidates[$left],
                        $candidates[$right],
                    )) {
                        continue;
                    }

                    [, $hash] = explode('|', $scope, 2);
                    $this->add(new DiagnosticFinding(
                        'assignment.overlap',
                        DiagnosticSeverity::Error,
                        'Equivalent non-revoked assignment periods overlap.',
                        $hash,
                    ));

                    continue 3;
                }
            }
        }
    }

    /**
     * @param  array<string, Model>  $definitions
     */
    private function inspectRoleReference(
        RoleAssignment $assignment,
        AssignmentScope $scope,
        array $definitions,
    ): void {
        $definition = $definitions[(string) $this->identifier($assignment, 'role_id')] ?? null;

        if (! $definition instanceof RoleContract) {
            $this->missingDefinition($scope, 'role');

            return;
        }

        $this->deactivatedDefinition($scope, $definition, 'role');
        $this->subjectGuard($assignment, $scope, $definition);
    }

    /**
     * @param  array<string, Model>  $definitions
     */
    private function inspectPermissionReference(
        PermissionAssignment $assignment,
        AssignmentScope $scope,
        array $definitions,
    ): void {
        $definition = $definitions[(string) $this->identifier($assignment, 'permission_id')] ?? null;

        if (! $definition instanceof PermissionContract) {
            $this->missingDefinition($scope, 'permission');

            return;
        }

        $this->deactivatedDefinition($scope, $definition, 'permission');
        $this->subjectGuard($assignment, $scope, $definition);
    }

    /**
     * @param  array<string, Model>  $roles
     * @param  array<string, Model>  $permissions
     */
    private function inspectRolePermissionReferences(
        RolePermission $assignment,
        AssignmentScope $scope,
        array $roles,
        array $permissions,
    ): void {
        $role = $roles[(string) $this->identifier($assignment, 'role_id')] ?? null;
        $permission = $permissions[(string) $this->identifier($assignment, 'permission_id')] ?? null;

        if (! $role instanceof RoleContract) {
            $this->missingDefinition($scope, 'role');
        } else {
            $this->deactivatedDefinition($scope, $role, 'role');
        }

        if (! $permission instanceof PermissionContract) {
            $this->missingDefinition($scope, 'permission');
        } else {
            $this->deactivatedDefinition($scope, $permission, 'permission');
        }

        if (
            $role instanceof RoleContract
            && $permission instanceof PermissionContract
            && $role->getAttribute('guard_name') !== $permission->getAttribute('guard_name')
        ) {
            $this->add(new DiagnosticFinding(
                'definition.guard_mismatch',
                DiagnosticSeverity::Error,
                'A role-permission assignment joins definitions from different guards.',
                $scope->hash(),
            ));
        }
    }

    private function missingDefinition(
        AssignmentScope $scope,
        string $kind,
    ): void {
        $this->add(new DiagnosticFinding(
            'definition.missing_'.$kind,
            DiagnosticSeverity::Error,
            'An assignment references a missing '.$kind.' definition.',
            $scope->hash(),
        ));
    }

    private function deactivatedDefinition(
        AssignmentScope $scope,
        Model $definition,
        string $kind,
    ): void {
        if (! method_exists($definition, 'trashed') || ! $definition->trashed()) {
            return;
        }

        $this->add(new DiagnosticFinding(
            'definition.deactivated_'.$kind,
            DiagnosticSeverity::Informational,
            'An assignment references a deactivated '.$kind.' definition.',
            $scope->hash(),
        ));
    }

    private function subjectGuard(
        RoleAssignment|PermissionAssignment $assignment,
        AssignmentScope $scope,
        Model $definition,
    ): void {
        $type = $assignment->getAttribute('subject_type');
        $id = $assignment->getAttribute('subject_id');

        if (! is_string($type) || (! is_int($id) && ! is_string($id))) {
            $this->coverage['data.subject_guards'] = false;

            return;
        }

        $cacheKey = hash('sha256', $type.'|'.(string) $id);

        if (! array_key_exists($cacheKey, $this->subjects)) {
            $this->subjects[$cacheKey] = $this->loadSubject($type, $id);
        }

        $subject = $this->subjects[$cacheKey];

        if (! $subject instanceof Model) {
            $this->coverage['data.subject_guards'] = false;
            $this->add(new DiagnosticFinding(
                'subject.inspection_unavailable',
                DiagnosticSeverity::Warning,
                'A referenced subject could not be inspected for guard consistency.',
                $scope->hash(),
            ));

            return;
        }

        try {
            $subjectGuard = $this->guards->resolve($subject);
        } catch (Throwable) {
            $this->coverage['data.subject_guards'] = false;
            $this->add(new DiagnosticFinding(
                'subject.guard_unavailable',
                DiagnosticSeverity::Warning,
                'A referenced subject guard could not be resolved.',
                $scope->hash(),
            ));

            return;
        }

        if ($subjectGuard !== $definition->getAttribute('guard_name')) {
            $this->add(new DiagnosticFinding(
                'assignment.subject_guard_mismatch',
                DiagnosticSeverity::Error,
                'An assignment definition conflicts with the subject guard.',
                $scope->hash(),
            ));
        }
    }

    private function loadSubject(string $type, int|string $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        try {
            $model = new $class;

            return $model->newQuery()->whereKey($id)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<stdClass>  $locks
     * @param  array<string, AssignmentScope>  $expected
     */
    private function inspectLocks(array $locks, array $expected): void
    {
        $actual = [];
        $canonical = [];

        foreach ($locks as $lock) {
            $kind = is_string($lock->assignment_kind ?? null)
                ? $lock->assignment_kind
                : '';
            $hash = is_string($lock->scope_hash ?? null)
                ? $lock->scope_hash
                : '';
            $key = is_string($lock->scope_key ?? null)
                ? $lock->scope_key
                : '';
            $reference = DiagnosticReference::for('coordination', $kind.'|'.$hash);

            if (AssignmentKind::tryFrom($kind) === null) {
                $this->add(new DiagnosticFinding(
                    'coordination.invalid_kind',
                    DiagnosticSeverity::Error,
                    'A coordination row contains an unsupported assignment kind.',
                    $reference,
                ));
            }

            if (! hash_equals(hash('sha256', $key), $hash)) {
                $this->add(new DiagnosticFinding(
                    'coordination.hash_mismatch',
                    DiagnosticSeverity::Error,
                    'A coordination scope key does not produce its stored hash.',
                    $reference,
                ));
            }

            $actual[$kind.'|'.$hash.'|'.$key] = true;
            $canonical[$kind.'|'.$key][] = $reference;
        }

        foreach ($canonical as $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $this->add(new DiagnosticFinding(
                'coordination.duplicate_scope',
                DiagnosticSeverity::Error,
                'More than one coordination row represents the same canonical scope.',
                $rows[0],
            ));
        }

        foreach ($expected as $scope) {
            $identity = $scope->kind()->value.'|'.$scope->hash().'|'.$scope->key();

            if (isset($actual[$identity])) {
                continue;
            }

            $this->add(new DiagnosticFinding(
                'coordination.missing_scope',
                DiagnosticSeverity::Error,
                'An assignment scope has no matching persistent coordination row.',
                $scope->hash(),
            ));
        }
    }

    private function identifier(Model $model, string $attribute): int|string
    {
        $value = $model->getAttribute($attribute);

        if (! is_int($value) && ! is_string($value)) {
            throw new \InvalidArgumentException('Invalid assignment identifier.');
        }

        return $value;
    }

    private function stringAttribute(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        if (! is_string($value)) {
            throw new \InvalidArgumentException('Invalid assignment string.');
        }

        return $value;
    }

    private function modelIdentifier(Model $model): int|string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? $key : 'unknown';
    }

    private function scopeIdentity(AssignmentScope $scope): string
    {
        return $scope->kind()->value.'|'.$scope->hash();
    }

    private function add(DiagnosticFinding $finding): void
    {
        $identity = $finding->code.'|'.($finding->reference ?? '');
        $this->findings[$identity] = $finding;
    }

    private function report(): DiagnosticReport
    {
        return new DiagnosticReport(
            array_values($this->findings),
            $this->coverage,
        );
    }
}
