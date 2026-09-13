<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Enums\DisputeResolution;
use App\Enums\OrderParty;
use App\Models\Dispute;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To both sides: the platform has decided (ADR 0051).
 *
 * **Both parties are told, and told the same thing.** One of them is about to
 * be worse off than they hoped, and finding that out from a bank statement
 * rather than from the marketplace is how a dispute becomes a complaint about
 * the marketplace.
 *
 * The note is the platform's reasoning and goes to both unedited. A decision
 * about somebody's money that arrives without one is exactly the support ticket
 * this mail exists to prevent - which is why the column is required.
 */
final class DisputeResolved extends QueuedNotification
{
    public function __construct(public Dispute $dispute, public OrderParty $party)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->dispute->order;
        $refunded = $this->dispute->resolution === DisputeResolution::Refunded;

        // The same order has two addresses, so the link is the reader's own.
        $page = $this->party === OrderParty::Buyer
            ? "/account/orders/{$order->reference}"
            : "/seller/orders/{$order->reference}";

        return (new MailMessage)
            ->subject("We have decided the dispute about order {$order->reference}")
            ->line($this->outcome($refunded))
            ->line('Why we decided it:')
            ->line('"'.$this->quoted((string) $this->dispute->resolution_note).'"')
            ->action('See the order', $this->frontend($page));
    }

    /**
     * What happened to the money, said plainly and from the reader's side.
     *
     * Four sentences rather than one with the names substituted in: "you have
     * been refunded" and "the buyer has been refunded" are different pieces of
     * news, and a template that tried to serve both would say neither well.
     */
    private function outcome(bool $refunded): string
    {
        $order = $this->dispute->order;

        if ($this->party === OrderParty::Buyer) {
            return $refunded
                ? 'We have refunded your payment for order '.$order->reference.' in full. '
                    .'It goes back to the card you paid with.'
                : 'We have released the payment for order '.$order->reference.' to '
                    .$this->quoted($order->seller->shop_name).', so the order is now complete.';
        }

        return $refunded
            ? 'We have refunded order '.$order->reference.' to the buyer in full, '
                .'so no payout will be made for it.'
            : 'We have released the payment for order '.$order->reference.' to you, '
                .'less the marketplace fee. The order is now complete.';
    }
}
