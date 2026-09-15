<?php

declare(strict_types=1);

namespace App\Actions\Appeals;

use App\Actions\Sellers\ReinstateShop;
use App\Exceptions\AppealNotAllowedException;
use App\Models\Appeal;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use App\Notifications\Appeals\AppealDecided;
use Illuminate\Support\Facades\DB;

/**
 * The platform looks again (ADR 0059).
 *
 * **An upheld appeal is the undo**, and it is the only one there is. ADR 0054
 * left "undoing a takedown" open - "upholding is one-way: there is no endpoint
 * that puts a listing back or unhides a review, so a mistake needs a database" -
 * and this is that endpoint. Every reversal therefore answers an appeal, so
 * every reversal has somebody's argument behind it and a decision somebody
 * recorded, which is the same accountability `DecideReport` gives a takedown.
 *
 * What upholding means depends on what was stopped:
 *
 * ```text
 * a shop      reinstated, through the action that already does it
 * a listing   the removal cleared - and left a draft, not put back on sale
 * a review    unhidden, and visible again wherever it was
 * ```
 *
 * **A listing comes back as a draft**, deliberately. The removal is what
 * `PublishProduct` refuses on, so clearing it restores the seller's *ability*
 * to sell the thing; putting it back on sale would be the platform making a
 * shop's decision for it, about a listing whose owner may well have moved on.
 */
final class DecideAppeal
{
    public function __construct(private readonly ReinstateShop $reinstate) {}

    /**
     * @param  bool  $upheld  whether the appeal was right
     * @param  string  $note  the platform's reasoning, which the appellant is sent
     *
     * @throws AppealNotAllowedException when it is already decided, or what it
     *                                   was about has gone
     */
    public function handle(Appeal $appeal, bool $upheld, string $note, User $staff): Appeal
    {
        $decided = DB::transaction(function () use ($appeal, $upheld, $note, $staff): Appeal {
            $locked = Appeal::query()->whereKey($appeal->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw AppealNotAllowedException::alreadyDecided();
            }

            $subject = $locked->appealable;

            /*
             * A morph carries no foreign key, so the thing appealed about can be
             * gone - a seller deleting a listing while waiting is an ordinary
             * thing to do. Dismissing is still fine; there is simply nothing
             * left to put back.
             */
            if ($upheld && $subject === null) {
                throw AppealNotAllowedException::subjectIsGone();
            }

            if ($upheld) {
                $this->lift($subject);
            }

            $locked->forceFill([
                'reviewed_at' => now(),
                'upheld' => $upheld,
                'outcome_note' => $note,
                'reviewed_by' => $staff->id,
            ])->save();

            return $locked;
        });

        // Whoever appealed hears either way: an appeal nobody answers is worse
        // than no appeal at all (ADR 0035).
        $decided->user->notify(new AppealDecided($decided));

        return $decided;
    }

    /**
     * Undoes the sanction.
     *
     * Inside the transaction with the decision, because a reversal that happened
     * without the decision authorising it is the one inconsistency here nobody
     * could explain afterwards - the same reasoning `DecideReport::takeDown()`
     * gives.
     */
    private function lift(?object $subject): void
    {
        if ($subject instanceof Seller) {
            // The action that already does this, rather than a second copy of
            // the columns. It refuses a shop that is not suspended, which
            // `RaiseAppeal` has already made impossible here.
            $this->reinstate->handle($subject);

            return;
        }

        if ($subject instanceof Product) {
            /*
             * The removal goes and the status does not. `products_removed_is_not_published`
             * only refuses a removed listing that is on sale, so a draft with no
             * removal is a state the database is perfectly happy with - and it
             * is the seller's to publish again.
             */
            $subject->forceFill([
                'removed_at' => null,
                'removal_reason' => null,
                'removed_by' => null,
            ])->save();

            return;
        }

        if ($subject instanceof Review) {
            $subject->forceFill([
                'hidden_at' => null,
                'hidden_reason' => null,
                'hidden_by' => null,
            ])->save();
        }
    }
}
