<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Models\Order;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a shop: the buyer says their parcel has not arrived yet.
 *
 * Told because it moves the date the shop's order completes, and eventually
 * the date it is paid. Worded as what it is - more time, not a complaint - for
 * the reason ADR 0014 gives: a slow courier is ordinary, and a process
 * where a date change will do helps nobody.
 */
final class CompletionExtended extends QueuedNotification
{
    public function __construct(public Order $order)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;
        $deadline = $order->auto_complete_at?->format('j M Y');

        return (new MailMessage)
            ->subject("More time for order {$order->reference}")
            ->line("The buyer says order {$order->reference} has not arrived yet.")
            ->line($deadline === null
                ? 'Its completion date has moved later.'
                : "It now completes on its own on {$deadline}, unless they confirm it arrived first.")
            ->line('This is not a complaint about the order. It only moves the date.')
            ->action('See the order', $this->frontend("/seller/orders/{$order->reference}"));
    }
}
