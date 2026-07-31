<?php

declare(strict_types=1);

namespace HangTuah\GateTrail\Audit;

/**
 * The accepted audit modes from PACKAGE_SPEC section 13.6.
 *
 * @internal
 */
enum AuditMode
{
    /**
     * Mutation and audit share one transaction. An audit failure rolls the
     * mutation back and throws.
     */
    case Strict;

    /**
     * The mutation commits first and the audit uses a separate transaction.
     * A failure is reported operationally and leaves the mutation committed.
     */
    case NonStrict;

    /**
     * No audit write is attempted. Not implemented yet.
     */
    case Disabled;
}
