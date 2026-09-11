<?php

declare(strict_types=1);

namespace App\Notifications\Sellers;

use App\Models\Seller;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/** To a shop: staff have approved it, and it is open. */
final class ShopApproved extends QueuedNotification
{
    public function __construct(public Seller $shop)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your shop is open')
            ->line($this->quoted($this->shop->shop_name).' has been approved.')
            ->line('Shoppers can now find everything it publishes.')
            ->action('Open your shop', $this->frontend('/seller'));
    }
}
