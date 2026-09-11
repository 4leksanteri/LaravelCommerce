<?php

declare(strict_types=1);

namespace App\Notifications\Sellers;

use App\Models\Seller;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a shop: staff did not approve it, and why.
 *
 * The reason is the one thing an applicant needs in order to fix the
 * application and send it again, which a rejection allows (ADR 0007).
 */
final class ShopRejected extends QueuedNotification
{
    public function __construct(public Seller $shop)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reason = $this->shop->rejection_reason;

        $mail = (new MailMessage)
            ->subject('Your shop application was not approved')
            ->line($this->quoted($this->shop->shop_name).' was not approved.');

        if ($reason !== null) {
            $mail->line('The reason given: '.$this->quoted($reason));
        }

        return $mail
            ->line('You can change what was wrong and apply again.')
            ->action('Apply again', $this->frontend('/sell'));
    }
}
