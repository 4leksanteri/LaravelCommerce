<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Models\Order;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/** To a buyer: the shop has committed to sending their order. */
final class OrderAccepted extends QueuedNotification
{
    public function __construct(public Order $order)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order;

        return (new MailMessage)
            ->subject("{$order->seller->shop_name} accepted order {$order->reference}")
            ->line(sprintf(
                '%s has accepted your order and will send it next. We will tell you when they have.',
                $this->quoted($order->seller->shop_name),
            ))
            ->action('See your order', $this->frontend("/account/orders/{$order->reference}"));
    }
}
