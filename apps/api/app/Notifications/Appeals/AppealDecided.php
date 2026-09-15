<?php

declare(strict_types=1);

namespace App\Notifications\Appeals;

use App\Models\Appeal;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Notifications\QueuedNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To whoever appealed: the platform has looked again (ADR 0059).
 *
 * **Sent either way.** An appeal nobody answers is worse than no appeal at all,
 * and a dismissal is the outcome somebody most needs told - they are waiting on
 * a shop that is still stopped.
 *
 * **An upheld listing appeal says the listing is a draft**, which is the line
 * this mail most has to get right. The removal is lifted rather than the
 * listing put back on sale, so a seller who went looking at the storefront
 * would not find it and would reasonably conclude the appeal had failed - the
 * mirror of the assumption `ListingTakenDown` guards against.
 */
final class AppealDecided extends QueuedNotification
{
    public function __construct(public Appeal $appeal)
    {
        parent::__construct();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $upheld = $this->appeal->upheld === true;

        $message = (new MailMessage)
            ->subject($upheld ? 'Your appeal was upheld' : 'Your appeal was not upheld')
            ->line($upheld
                ? 'We have looked again, and the decision has been reversed.'
                : 'We have looked again, and the original decision stands.')
            ->line('Why:')
            ->line('"'.$this->quoted((string) $this->appeal->outcome_note).'"');

        foreach ($this->consequence($upheld) as $line) {
            $message->line($line);
        }

        [$label, $address] = $this->destination();

        return $message->action($label, $address);
    }

    /**
     * What the outcome means for the thing that was stopped.
     *
     * @return list<string>
     */
    private function consequence(bool $upheld): array
    {
        $subject = $this->appeal->appealable;

        if (! $upheld) {
            return ['Nothing has changed, and what was stopped stays stopped.'];
        }

        return match (true) {
            $subject instanceof Seller => ['Your shop is trading again, and its listings are back in the storefront.'],

            // The one that has to be said plainly.
            $subject instanceof Product => [
                'The listing is in your catalogue as a draft, and you can put it back on sale whenever you are ready.',
                'It is not on sale yet: reversing the removal gives you the choice back rather than making it for you.',
            ],

            $subject instanceof Review => ['Your review is visible again, exactly as you wrote it.'],

            // The subject was deleted while the appeal was waiting, which
            // upholding refuses - so this is unreachable from `DecideAppeal`.
            default => [],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function destination(): array
    {
        $subject = $this->appeal->appealable;

        if ($subject instanceof Review) {
            return ['Your account', $this->frontend('/account')];
        }

        if ($subject instanceof Product) {
            return ['Your listings', $this->frontend('/seller/listings')];
        }

        return ['Your shop', $this->frontend('/seller')];
    }
}
