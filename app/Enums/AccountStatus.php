<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
    case NeedsRevision = 'needs_revision';
    case Rejected = 'rejected';
    case Suspended = 'suspended';
    case Deactivated = 'deactivated';
    case Banned = 'banned';
}
