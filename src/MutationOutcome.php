<?php

declare(strict_types=1);

namespace HangTuah\GateTrail;

enum MutationOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deactivated = 'deactivated';
    case Restored = 'restored';
    case Revoked = 'revoked';
    case Unchanged = 'unchanged';
}
