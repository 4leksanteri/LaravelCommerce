<?php

declare(strict_types=1);

namespace App\Notifications\Sellers;

use App\Models\Seller;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a shop: the platform has stopped it trading, and why (ADR 0052).
 *
 * **The reason goes in the mail**, because it is the only thing the shop has to
 * act on and the alternative is a shop discovering it has been stopped by
 * noticing its listings have gone.
 *
 * **What still works is said too.** A suspension does not cancel what the shop
 * has already sold, and a seller who assumed it had would stop posting parcels
 * that buyers have paid for - which would turn a suspension into a row of
 * disputes.
 */
final class ShopSuspended extends QueuedNotification
{
    public function __construct(public Seller $shop)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your shop has been suspended')
            ->line($this->quoted($this->shop->shop_name).' has been suspended, so it is no longer '
                .'listed and nothing new can be published.')
            ->line('Why:')
            ->line('"'.$this->quoted((string) $this->shop->suspension_reason).'"')
            ->line('Orders you have already taken are not cancelled. Please send what has been '
                .'paid for as usual: buyers can still confirm a parcel arrived, and their payments '
                .'are released to you as they do.')
            ->action('Your shop', $this->frontend('/seller'));
    }
}
