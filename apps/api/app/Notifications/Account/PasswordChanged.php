<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To an account whose password has just changed.
 *
 * Told at the account's own address, which is where somebody who did not make
 * the change would find out that somebody else did - with the way to take the
 * account back, which is a password reset to that same address (ADR 0034).
 */
final class PasswordChanged extends QueuedNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your password was changed')
            ->line('The password on your account was changed, and everywhere else it was signed in has been signed out.')
            ->line('If you did not make the change, reset your password now.')
            ->action('Reset your password', $this->frontend('/forgot-password'));
    }
}
