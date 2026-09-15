<?php

declare(strict_types=1);

namespace App\Actions\Appeals;

use App\Exceptions\AppealNotAllowedException;
use App\Models\Appeal;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Somebody answers back about a decision the platform took (ADR 0059).
 *
 * **Only against something that is actually stopped.** A listing that was never
 * removed, a shop that is trading and a review nobody hid have no decision
 * behind them to argue with, and an upheld appeal would have nothing to lift.
 * That is asked here rather than assumed from the route, because the route
 * proves ownership and this proves there is a case.
 *
 * **Raising one changes nothing.** The shop stays stopped, the listing stays
 * down and the review stays hidden until a person decides. An appeal that
 * suspended the sanction would make appealing a free way back, and every
 * enforcement decision would be appealed the moment it landed.
 *
 * Who may appeal is settled before this runs: each endpoint resolves its
 * subject through the caller's own relations - their shop, their listing, their
 * review - so there is no policy here and nothing for a caller to substitute
 * (ADR 0008).
 */
final class RaiseAppeal
{
    /**
     * @throws AppealNotAllowedException when there is nothing stopped to appeal,
     *                                   or an appeal is already open
     */
    public function handle(User $appellant, Product|Review|Seller $subject, string $reason): Appeal
    {
        if (! $this->isStopped($subject)) {
            throw AppealNotAllowedException::nothingToAppeal();
        }

        $appeal = new Appeal;

        $appeal->forceFill([
            'appealable_type' => $subject::class,
            'appealable_id' => $subject->getKey(),
            'user_id' => $appellant->id,
            'reason' => $reason,
        ]);

        try {
            $appeal->save();
        } catch (UniqueConstraintViolationException) {
            /*
             * The partial unique index is the guard rather than a read before
             * the write, for the reason `ReportContent` gives: two requests
             * arriving together both find nothing and both insert, and the
             * database is the only party that can see both.
             *
             * It covers open appeals only, so somebody whose first appeal was
             * dismissed may try again - they may have found the paperwork since.
             */
            throw AppealNotAllowedException::alreadyAppealing();
        }

        $appeal->setRelation('appealable', $subject);

        return $appeal;
    }

    /**
     * Whether there is a decision here to argue with.
     *
     * Each asks the model rather than the columns, so there is one definition of
     * "stopped" per thing and this is not a second one.
     */
    private function isStopped(Product|Review|Seller $subject): bool
    {
        return match (true) {
            $subject instanceof Seller => $subject->isSuspended(),
            $subject instanceof Product => $subject->wasRemovedByStaff(),
            $subject instanceof Review => $subject->isHidden(),
        };
    }
}
