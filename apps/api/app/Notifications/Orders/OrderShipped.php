<?php

declare(strict_types=1);

namespace App\Notifications\Orders;

use App\Models\Order;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a buyer: the shop has sent their order, and the clock has started.
 *
 * The date it completes on its own is the thing worth telling somebody (ADR
 * 0014), and so is the way to push it back, because a late parcel is ordinary
 * and a buyer who does not know they can ask for more time will not.
 */
final class OrderShipped extends QueuedNotification
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
            ->subject("{$order->seller->shop_name} sent order {$order->reference}")
            ->line($this->quoted($order->seller->shop_name).' has sent your order.')
            ->line($deadline === null
                ? 'Once it arrives and you have checked it over, confirm it arrived.'
                : "Once it arrives and you have checked it over, confirm it arrived. If you do not, it completes on its own on {$deadline}.")
            ->line("If it has not arrived by then, you can give it more time from the order's page.")
            ->action('See your order', $this->frontend("/account/orders/{$order->reference}"));
    }
}
