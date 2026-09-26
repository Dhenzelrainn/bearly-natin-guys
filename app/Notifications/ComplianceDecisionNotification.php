<?php

namespace App\Notifications;

use App\Models\ProductViolation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ComplianceDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(private ProductViolation $violation, private string $decision) {}

    public function via(object $notifiable): array { return ['database']; }

    public function toArray(object $notifiable): array
    {
        return ['title'=>'Product compliance decision','decision'=>$this->decision,
            'violation_no'=>$this->violation->violation_no,'product'=>$this->violation->product->name,
            'message'=>'An administrator recorded a compliance decision for your product listing.'];
    }
}
