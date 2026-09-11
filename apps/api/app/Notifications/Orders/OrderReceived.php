<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Models\Order;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a shop: somebody has ordered from it.
 *
 * Sent to the shop's contact address (Seller::routeNotificationForMail). The
 * buyer's name is in it, as it is on the shop's side of the order; the delivery
 * address is not, and stays on the order's page behind a session.
 */
final class OrderReceived extends QueuedNotification
{
    public function __construct(public Order $order)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;

        $mail = (new MailMessage)
            ->subject("New order {$order->reference}")
            ->line(sprintf(
                '%s has ordered from %s:',
                $this->quoted($order->user->name),
                $this->quoted($order->seller->shop_name),
            ));

        foreach ($order->items as $item) {
            $mail->line(sprintf(
                '%d x %s, %s: %s',
                $item->quantity,
                $this->quoted($item->product_name),
                $this->quoted($item->variant_name),
                $order->currency->format($item->lineTotalMinor()),
            ));
        }

        return $mail
            ->line('Total: '.$order->currency->format($order->total_minor))
            ->line('Accepting it tells the buyer you will send it. If you cannot, cancel it and say why.')
            ->action('Open your shop', $this->frontend('/seller'));
    }
}
