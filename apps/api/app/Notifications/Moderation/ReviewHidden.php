<?php

declare(strict_types=1);

namespace App\Notifications\Moderation;

use App\Models\Report;
use App\Models\Review;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To whoever wrote it: their review is no longer shown, and why (ADR 0054).
 *
 * **Not "your review was deleted", because it was not.** ADR 0047 refused
 * deletion, and hiding is what the platform does instead: the words are still
 * theirs, they can still see and change them, and the listing they bought from
 * still counts them as having reviewed it. Saying "deleted" would be both
 * untrue and more alarming than what happened.
 */
final class ReviewHidden extends QueuedNotification
{
    public function __construct(public Review $review, public Report $report)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $listing = $this->review->product;

        return (new MailMessage)
            ->subject('Your review is no longer shown')
            ->line('Your review of '.$this->quoted($listing->name).' is no longer shown to other '
                .'shoppers.')
            ->line('Why:')
            ->line('"'.$this->quoted((string) $this->report->outcome_note).'"')
            ->line('It has not been deleted, and nothing about what you bought has changed.')
            ->action(
                'The listing',
                $this->frontend("/shops/{$listing->seller->slug}/products/{$listing->slug}"),
            );
    }
}
