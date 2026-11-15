<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class RegistrationCode extends Mailable
{
    public function __construct(public string $code) {}

    public function build(): static
    {
        $data = [
            'code' => $this->code,
            'expiresInMinutes' => max(1, (int) ceil((int) config('email-verification.ttl', 300) / 60)),
        ];

        return $this->subject('Your Bearly verification code')
            ->view('emails.registration-code', $data)
            ->text('emails.text.registration-code', $data);
    }
}
