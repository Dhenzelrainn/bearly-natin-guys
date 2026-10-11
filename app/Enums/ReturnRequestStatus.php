<?php

namespace App\Enums;

enum ReturnRequestStatus: string
{
    case AwaitingSeller = 'awaiting_seller';
    case UnderReview = 'under_review';
    case AwaitingEvidence = 'awaiting_evidence';
    case ReturnPickupRequested = 'return_pickup_requested';
    case ReturnInTransit = 'return_in_transit';
    case AwaitingInspection = 'awaiting_inspection';
    case RefundPending = 'refund_pending';
    case Resolved = 'resolved';
    case Rejected = 'rejected';
}
