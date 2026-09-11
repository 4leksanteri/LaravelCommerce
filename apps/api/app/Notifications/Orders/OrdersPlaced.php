<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Models\Order;
use App\Notifications\QueuedNotification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a buyer: what one checkout placed.
 *
 * One mail for the whole checkout rather than one per shop. The buyer pressed
 * one button, and three receipts arriving at once for it would read as three
 * purchases. Each order is listed with its own shop, reference and total, in
 * its own currency, and there is no total across them (ADR 0004).
 */
final class OrdersPlaced extends QueuedNotification
{
    /**
     * @param  Collection<int, Order>  $orders
     */
    public function __construct(public Collection $orders)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $only = $this->orders->count() === 1 ? $this->orders->first() : null;

        $mail = (new MailMessage)
            ->subject($only instanceof Order
                ? "Your order {$only->reference} is placed"
                : "Your {$this->orders->count()} orders are placed")
            ->line($only instanceof Order
                ? 'Thank you. The shop accepts your order before sending it, and we will tell you when it does.'
                : 'Thank you. One order went to each shop, and each accepts its own before sending it. We will tell you as they do.');

        foreach ($this->orders as $order) {
            $mail->line(sprintf(
                '%s: %s, %s',
                $order->reference,
                $this->quoted($order->seller->shop_name),
                $order->currency->format($order->total_minor),
            ));
        }

        return $mail
            ->line('No card was charged: taking payment is not built yet.')
            ->action(
                $only instanceof Order ? 'See your order' : 'See your orders',
                $this->frontend($only instanceof Order ? "/account/orders/{$only->reference}" : '/account/orders'),
            );
    }
}
