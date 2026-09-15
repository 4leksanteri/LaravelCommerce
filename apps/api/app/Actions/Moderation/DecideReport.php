<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Actions\Platform\RecordDecision;
use App\Enums\DecisionKind;
use App\Enums\ProductStatus;
use App\Exceptions\ReportNotAllowedException;
use App\Models\Product;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use App\Notifications\Moderation\ListingTakenDown;
use App\Notifications\Moderation\ReviewHidden;
use Illuminate\Support\Facades\DB;

/**
 * The platform decides what to do about a report (ADR 0054).
 *
 * **Upholding one is the takedown.** There is deliberately no separate "remove
 * this listing" endpoint: every removal answers a report, so every removal has
 * a reason somebody gave and a decision somebody recorded. A takedown with no
 * report behind it would be the one moderation action with no trail.
 *
 * What upholding means depends on what was reported, and there are exactly two
 * kinds today:
 *
 * ```text
 * a listing   taken off sale and kept down - PublishProduct refuses it after
 * a review    hidden from everyone but its author, and the row stays
 * ```
 *
 * **Neither is a delete.** A listing keeps its order history, and a review
 * keeps its author's one-per-listing slot - so hiding one cannot be used to
 * win a second attempt at reviewing something.
 *
 * The decision is recorded before anybody is told, and the mail waits for the
 * commit like every other (ADR 0035).
 */
final class DecideReport
{
    public function __construct(private readonly RecordDecision $record) {}

    /**
     * @param  bool  $upheld  whether the report was right
     * @param  string  $note  the platform's reasoning, which the owner is sent
     *
     * @throws ReportNotAllowedException when it is already decided, or what it
     *                                   pointed at has gone
     */
    public function handle(Report $report, bool $upheld, string $note, User $staff): Report
    {
        $decided = DB::transaction(function () use ($report, $upheld, $note, $staff): Report {
            $locked = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ReportNotAllowedException::alreadyDecided();
            }

            $subject = $locked->reportable;

            /*
             * A morph carries no foreign key, so the thing reported can be
             * gone - a seller deleting a flagged listing between the report and
             * the decision is an ordinary race. Dismissing is still fine; there
             * is simply nothing left to uphold.
             */
            if ($upheld && $subject === null) {
                throw ReportNotAllowedException::subjectIsGone();
            }

            if ($upheld) {
                $this->takeDown($subject, $note, $staff);
            }

            $locked->forceFill([
                'reviewed_at' => now(),
                'upheld' => $upheld,
                'outcome_note' => $note,
                'reviewed_by' => $staff->id,
            ])->save();

            return $locked;
        });

        if ($upheld) {
            $this->tellTheOwner($decided);
        }

        return $decided;
    }

    /**
     * Off sale, or out of sight.
     *
     * Written inside the transaction with the report, because a takedown that
     * happened without the decision that ordered it is the one inconsistency
     * here nobody could explain afterwards.
     */
    private function takeDown(?object $subject, string $note, User $staff): void
    {
        if ($subject instanceof Product) {
            // Status and `published_at` move together, because
            // `products_published_at_check` ties them - and
            // `products_removed_is_not_published` refuses a removed listing
            // that is still on sale.
            $subject->forceFill([
                'status' => ProductStatus::Draft,
                'published_at' => null,
                'removed_at' => now(),
                'removal_reason' => $note,
                'removed_by' => $staff->id,
            ])->save();

            /*
             * On the shop's record, because an upheld appeal will one day clear
             * the three columns above and they are the only trace of this
             * (ADR 0060).
             */
            $this->record->handle(
                DecisionKind::ListingRemoved,
                $subject->seller,
                $subject,
                $note,
                $staff,
            );

            return;
        }

        if ($subject instanceof Review) {
            $subject->forceFill([
                'hidden_at' => now(),
                'hidden_reason' => $note,
                'hidden_by' => $staff->id,
            ])->save();

            // Deliberately not on any shop's record: this is a decision about
            // what a buyer wrote, not about the shop they wrote it under
            // (ADR 0060).
        }
    }

    /**
     * Whoever wrote the thing that came down hears, and hears why.
     *
     * The reporter is told nothing. They said their piece and the platform
     * acted or did not; telling them the outcome would make every dismissed
     * report an argument, and every upheld one a scoreboard.
     */
    private function tellTheOwner(Report $report): void
    {
        $subject = $report->reportable;

        if ($subject instanceof Product) {
            $subject->seller->notify(new ListingTakenDown($subject, $report));

            return;
        }

        if ($subject instanceof Review) {
            $subject->user->notify(new ReviewHidden($subject, $report));
        }
    }
}
