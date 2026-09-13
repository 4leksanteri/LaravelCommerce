<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Enums\OrderActor;
use App\Enums\OrderParty;
use App\Models\Order;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An order has been called off, told to whoever did not call it off.
 *
 * ```text
 * cancelled by    told
 * the buyer       the shop
 * the shop        the buyer, with the shop's reason
 * the deadline    both: nobody chose it, so both need to hear
 * ```
 *
 * The words depend on who is reading as well as who cancelled, so the reader
 * is part of the notification rather than guessed from the notifiable.
 */
final class OrderCancelled extends QueuedNotification
{
    public function __construct(public Order $order, public OrderParty $reader)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("Order {$this->order->reference} was cancelled");

        foreach ($this->explanation() as $line) {
            $mail->line($line);
        }

        return $this->reader === OrderParty::Buyer
            ? $mail->action('See your order', $this->frontend("/account/orders/{$this->order->reference}"))
            : $mail->action('See the order', $this->frontend("/seller/orders/{$this->order->reference}"));
    }

    /**
     * @return list<string>
     */
    private function explanation(): array
    {
        $reference = $this->order->reference;
        $shop = $this->quoted($this->order->seller->shop_name);
        $toBuyer = $this->reader === OrderParty::Buyer;
        $reason = $this->order->cancellation_reason;

        return match ($this->order->cancelled_by) {
            OrderActor::Buyer => $toBuyer
                ? ["You cancelled order {$reference}."]
                : ["The buyer cancelled order {$reference} before you accepted it, and its stock is back on sale."],
            OrderActor::Seller => $toBuyer
                ? array_filter([
                    "{$shop} cancelled your order {$reference}.",
                    $reason === null ? null : 'Their reason: '.$this->quoted($reason),
                ])
                : ["You cancelled order {$reference}."],
            /*
             * Two clocks end an order, and telling a buyer the wrong one is
             * worse than saying nothing: somebody whose card was declined would
             * be told the shop never answered (ADR 0042, ADR 0046).
             *
             * The unpaid case only ever reaches the buyer - a shop is not told
             * about an order it was never shown - and the third arm is what
             * keeps this total rather than something that can be reached.
             */
            OrderActor::Deadline => match (true) {
                $toBuyer && ! $this->order->isPaid() => [
                    "Order {$reference} was cancelled because it was not paid for in time.",
                    'Nothing was charged. What was in it is back in your basket, so you can try again.',
                ],
                $toBuyer => ["{$shop} did not accept your order {$reference} in time, so it was cancelled."],
                default => ["Order {$reference} was not accepted in time, so it was cancelled and its stock is back on sale."],
            },
            null => ["Order {$reference} was cancelled."],
        };
    }
}
