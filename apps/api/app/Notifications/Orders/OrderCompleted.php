<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Enums\OrderActor;
use App\Enums\OrderParty;
use App\Models\Order;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An order is complete.
 *
 * When the buyer confirmed it, only the shop is told: the buyer pressed the
 * button and is looking at the result. When its deadline passed, both are,
 * because nobody chose it - and the buyer especially should know that the
 * marketplace has concluded their parcel arrived (ADR 0014).
 */
final class OrderCompleted extends QueuedNotification
{
    public function __construct(public Order $order, public OrderParty $reader)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reference = $this->order->reference;
        $toBuyer = $this->reader === OrderParty::Buyer;

        $line = match ($this->order->completed_by) {
            OrderActor::Buyer => $toBuyer
                ? "You confirmed that order {$reference} arrived."
                : "The buyer confirmed that order {$reference} arrived.",
            OrderActor::Deadline => $toBuyer
                ? "Order {$reference} completed on its own: its deadline passed without you confirming it arrived or asking for more time."
                : "Order {$reference} completed on its own: its deadline passed without the buyer saying anything more.",
            OrderActor::Seller, null => "Order {$reference} is complete.",
        };

        $mail = (new MailMessage)
            ->subject("Order {$reference} is complete")
            ->line($line);

        return $toBuyer
            ? $mail->action('See your order', $this->frontend("/account/orders/{$reference}"))
            : $mail->action('See the order', $this->frontend("/seller/orders/{$reference}"));
    }
}
