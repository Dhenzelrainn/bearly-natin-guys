<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class BearlyPasswordResetNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $resetUrl = $this->resetUrl($notifiable);
        $expiresInMinutes = (int) config(
            'auth.passwords.'.config('auth.defaults.passwords').'.expire',
        );
        $data = compact('resetUrl', 'expiresInMinutes');

        return (new MailMessage)
            ->subject('Reset your Bearly password')
            ->view('emails.password-reset', $data)
            ->text('emails.text.password-reset', $data);
    }
}
