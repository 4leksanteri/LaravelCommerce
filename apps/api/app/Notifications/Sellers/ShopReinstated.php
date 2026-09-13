<?php

declare(strict_types=1);

namespace App\Notifications\Sellers;

use App\Models\Seller;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/** To a shop: the suspension has been lifted and it is trading again. */
final class ShopReinstated extends QueuedNotification
{
    public function __construct(public Seller $shop)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your shop is open again')
            ->line('The suspension on '.$this->quoted($this->shop->shop_name).' has been lifted.')
            ->line('It is listed again, and everything it had published is back on sale.')
            ->action('Open your shop', $this->frontend('/seller'));
    }
}
