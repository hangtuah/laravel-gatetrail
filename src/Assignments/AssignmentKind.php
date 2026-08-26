<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Assignments;

/**
 * @internal
 */
enum AssignmentKind: string
{
    case Role = 'role';
    case Permission = 'permission';
    case RolePermission = 'role_permission';
}
