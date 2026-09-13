<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Models\Dispute;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the shop: the buyer says something went wrong (ADR 0051).
 *
 * **The shop hears immediately, and hears why.** A dispute holds the money that
 * was about to be theirs, so being told after the fact - or not at all - would
 * be the marketplace taking something away quietly. The buyer's own words go in
 * the mail, because "there is a dispute" with no reason is a notice nobody can
 * act on.
 *
 * There is no button to answer it here. The shop replies on the order, where
 * the conversation already lives (ADR 0050), and the platform decides.
 */
final class DisputeOpened extends QueuedNotification
{
    public function __construct(public Dispute $dispute)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->dispute->order;

        return (new MailMessage)
            ->subject("{$order->user->name} has disputed order {$order->reference}")
            ->line($this->quoted($order->user->name).' has raised a dispute about order '
                .$order->reference.', and said:')

            // Their words, escaped: the template renders Markdown, and this is
            // text somebody typed.
            ->line('"'.$this->quoted($this->dispute->reason).'"')
            ->line('The payment for this order is held until we have looked at it, '
                .'and it will not complete on its own in the meantime.')
            ->line('You can reply to them on the order, and we may ask you for more.')
            ->action('See the order', $this->frontend("/seller/orders/{$order->reference}"));
    }
}
