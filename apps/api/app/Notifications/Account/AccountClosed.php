<?php

declare(strict_types=1);

namespace App\Notifications\Account;

use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the address an account has just stopped using (ADR 0058).
 *
 * **The name is carried rather than read.** Every other notification here asks
 * the notifiable who it is; by the time this one sends, the account is called
 * "Closed account" and its address is a `.invalid` one that cannot receive
 * anything. So the closure captures both beforehand and this is routed to the
 * address on the way out - which is the only place somebody who did not close
 * the account would find out that somebody else did.
 *
 * It says what was kept, because "your account is closed" invites exactly the
 * question this answers: the orders are still there, and so is anything said
 * about a shop in public.
 */
final class AccountClosed extends QueuedNotification
{
    public function __construct(private readonly string $name) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your account has been closed')
            ->greeting("Goodbye, {$this->name}.")
            ->line('Your account is closed. Your name, your saved addresses and your basket have been removed, and nobody can sign in with it again.')
            ->line('Orders you placed are kept, because a receipt belongs to the shop as much as to you, and anything you reviewed in public stays where it is - without your name on it.')
            ->line('If you did not do this, reply to this message: the account cannot be reopened from here.');
    }
}
