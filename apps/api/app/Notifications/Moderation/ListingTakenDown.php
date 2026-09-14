<?php

declare(strict_types=1);

namespace App\Notifications\Moderation;

use App\Models\Product;
use App\Models\Report;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To a shop: one of its listings has been taken down, and why (ADR 0054).
 *
 * **The reason goes in the mail**, because it is the only thing the shop has to
 * act on - and because a seller who found a listing missing with no explanation
 * would reasonably assume a bug and publish it again, which is the one thing
 * this cannot allow.
 *
 * It says the listing stays in the catalogue as a draft, which is true and
 * matters: nothing was deleted, the order history is intact, and what was sold
 * from it is unaffected.
 */
final class ListingTakenDown extends QueuedNotification
{
    public function __construct(public Product $listing, public Report $report)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->listing->name} has been taken down")
            ->line($this->quoted($this->listing->name).' has been taken off sale by the '
                .'marketplace, and cannot be published again.')
            ->line('Why:')
            ->line('"'.$this->quoted((string) $this->report->outcome_note).'"')
            ->line('The listing itself is still in your catalogue as a draft, and nothing about '
                .'orders already placed from it has changed.')
            ->action('Your listings', $this->frontend('/seller/listings'));
    }
}
