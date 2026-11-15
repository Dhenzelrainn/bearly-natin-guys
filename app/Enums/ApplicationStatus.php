<?php

namespace App\Enums;

enum ApplicationStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case NeedsRevision = 'needs_revision';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
}
