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
    ) {}

    public function build(): static
    {
        $decisionLabel = match ($this->decision) {
            'needs_revision' => 'needs revision',
            default => $this->decision,
        };

        $data = [
            'applicantName' => $this->applicantName,
            'role' => $this->role,
            'decision' => $this->decision,
            'decisionLabel' => str($decisionLabel)->replace('_', ' ')->title()->toString(),
            'reason' => $this->reason,
        ];

        return $this
            ->subject('Bearly '.ucfirst($this->role).' application '.$decisionLabel)
            ->view('emails.application-decision-notice', $data)
            ->text('emails.text.application-decision-notice', $data);
    }
}
