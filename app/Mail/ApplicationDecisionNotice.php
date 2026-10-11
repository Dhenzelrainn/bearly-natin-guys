<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicationDecisionNotice extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $applicantName,
        public string $role,
        public string $decision,
        public ?string $reason = null,
    ) {
    }

    public function build(): static
    {
        $decisionLabel = match ($this->decision) {
            'needs_revision' => 'needs revision',
            default => $this->decision,
        };

        return $this
            ->subject('Bearly '.ucfirst($this->role).' application '.$decisionLabel)
            ->text('emails.application-decision-notice');
    }
}
