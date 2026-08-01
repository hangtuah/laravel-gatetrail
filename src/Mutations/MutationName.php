<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Mutations;

/**
 * The canonical name of every managed GateTrail mutation.
 *
 * One vocabulary serves three consumers that must never disagree: the audit
 * `event` column, the `MutationCommitted` domain event, and — once ADR 0011's
 * retry orchestrator exists — the operation reported by a concurrency
 * exhaustion. Handlers previously wrote
 * these strings as literals while the event class kept a private copy of the
 * list, which is exactly the drift this class removes.
 *
 * This is a constants and predicate class, not a value object: there is
 * nothing to construct, nothing to normalize, and no invariant beyond
 * membership.
 *
 * @internal Constants are source-visible, but this vocabulary is not a
 *           supported consumer contract. Use the audit `event` column or
 *           `MutationCommitted::$mutation` instead.
 *
 * @see docs/decisions/0011-bounded-concurrency-retry.md
 */
final class MutationName
{
    public const ROLE_ASSIGNED = 'role.assigned';

    public const ROLE_REVOKED = 'role.revoked';

    public const PERMISSION_GRANTED_TO_SUBJECT = 'permission.granted_to_subject';

    public const PERMISSION_REVOKED_FROM_SUBJECT = 'permission.revoked_from_subject';

    public const PERMISSION_GRANTED_TO_ROLE = 'permission.granted_to_role';

    public const PERMISSION_REVOKED_FROM_ROLE = 'permission.revoked_from_role';

    public const ROLE_CREATED = 'role.created';

    public const ROLE_UPDATED = 'role.updated';

    public const ROLE_DEACTIVATED = 'role.deactivated';

    public const ROLE_RESTORED = 'role.restored';

    public const PERMISSION_CREATED = 'permission.created';

    public const PERMISSION_UPDATED = 'permission.updated';

    public const PERMISSION_DEACTIVATED = 'permission.deactivated';

    public const PERMISSION_RESTORED = 'permission.restored';

    /**
     * The six mutations that acquire an assignment coordination row, and are
     * therefore the only ones ADR 0011 makes retryable.
     *
     * @var list<string>
     */
    private const ASSIGNMENTS = [
        self::ROLE_ASSIGNED,
        self::ROLE_REVOKED,
        self::PERMISSION_GRANTED_TO_SUBJECT,
        self::PERMISSION_REVOKED_FROM_SUBJECT,
        self::PERMISSION_GRANTED_TO_ROLE,
        self::PERMISSION_REVOKED_FROM_ROLE,
    ];

    /**
     * The eight definition mutations. Their races are semantic rather than
     * transient, so they stay single-attempt.
     *
     * @var list<string>
     */
    private const DEFINITIONS = [
        self::ROLE_CREATED,
        self::ROLE_UPDATED,
        self::ROLE_DEACTIVATED,
        self::ROLE_RESTORED,
        self::PERMISSION_CREATED,
        self::PERMISSION_UPDATED,
        self::PERMISSION_DEACTIVATED,
        self::PERMISSION_RESTORED,
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(self::ASSIGNMENTS, self::DEFINITIONS);
    }

    /**
     * @return list<string>
     */
    public static function assignments(): array
    {
        return self::ASSIGNMENTS;
    }

    public static function isKnown(string $name): bool
    {
        return in_array($name, self::all(), true);
    }

    public static function isAssignment(string $name): bool
    {
        return in_array($name, self::ASSIGNMENTS, true);
    }
}
