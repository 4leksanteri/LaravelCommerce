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

        $mail = (new MailMessage)
            ->subject("{$order->seller->shop_name} sent order {$order->reference}")
            ->line($this->quoted($order->seller->shop_name).' has sent your order.');

        /*
         * Who has it, and the way to follow it (ADR 0049).
         *
         * In the mail rather than only on the page, because "where is my
         * parcel" is asked the moment this arrives - and a buyer who has to go
         * and find the order first is a buyer who writes to the shop instead.
         *
         * The link is a button of its own only when there is a carrier to link
         * to; a number without one is still worth saying, because it is what
         * somebody quotes down a telephone.
         */
        $carrier = $order->carrier;
        $number = $order->tracking_number;

        if ($number !== null) {
            $mail->line($carrier === null
                ? "Tracking number: {$number}"
                : "{$carrier->label()}, tracking number {$number}.");
        }

        return $this->closing($mail, $deadline, $order);
    }

    /**
     * The part that is the same whether or not anybody is tracking anything.
     *
     * **A mail has one button.** `MailMessage::action()` replaces whatever was
     * there before rather than adding beside it, so asking for two silently
     * kept the second - the link this whole feature exists to deliver was the
     * one that disappeared, and only a test on `actionText` noticed.
     *
     * Tracking wins where there is tracking. It is what somebody opened this
     * mail to find, and the order's page is still one line below as a link, so
     * nothing is lost by demoting it.
     */
    private function closing(MailMessage $mail, ?string $deadline, Order $order): MailMessage
    {
        $page = $this->frontend("/account/orders/{$order->reference}");
        $tracking = $order->trackingUrl();

        $mail
            ->line($deadline === null
                ? 'Once it arrives and you have checked it over, confirm it arrived.'
                : "Once it arrives and you have checked it over, confirm it arrived. If you do not, it completes on its own on {$deadline}.")
            ->line("If it has not arrived by then, you can give it more time from the order's page.");

        if ($tracking === null) {
            return $mail->action('See your order', $page);
        }

        return $mail
            ->action('Track your parcel', $tracking)
            ->line("Your order: {$page}");
    }
}
