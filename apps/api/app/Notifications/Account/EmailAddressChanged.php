<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the address an account has just left.
 *
 * After the change, the old address is the only one its owner still reads, so
 * it is the only place somebody who did not make the change can find out that
 * somebody else did (ADR 0034).
 *
 * The new address is shown with most of it hidden. The reader of this mail is
 * either the owner, who knows it, or somebody whose old address this was, who
 * has no business learning the new one in full.
 */
final class EmailAddressChanged extends QueuedNotification
{
    public function __construct(public string $newAddress)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your email address was changed')
            ->line(sprintf(
                'The email address on your %s account was changed from this address to %s.',
                config('app.name'),
                $this->masked($this->newAddress),
            ))
            ->line('If you made the change, there is nothing to do.')
            ->line('If you did not, somebody else has your password.');
    }

    /** `a*****@example.test`: the first letter and the domain. */
    private function masked(string $address): string
    {
        [$local, $domain] = array_pad(explode('@', $address, 2), 2, '');

        return mb_substr($local, 0, 1).str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }
}
