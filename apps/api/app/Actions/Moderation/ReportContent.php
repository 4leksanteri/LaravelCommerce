<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Enums\ReportReason;
use App\Exceptions\ReportNotAllowedException;
use App\Models\Product;
use App\Models\Report;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Somebody says a listing or a review should not be here (ADR 0054).
 *
 * **Anybody signed in may report anything they can see**, and what they can see
 * is already settled before this runs: a listing in an unapproved shop and a
 * hidden review are both 404 from the storefront's own scopes. So there is no
 * policy here - the query carried the rule, as ADR 0008 prefers.
 *
 * **Reporting is not accusing.** Nothing happens to the listing when a report
 * is filed: it stays on sale until a person decides. The alternative - hiding
 * on report - would hand anybody with two accounts the power to close a
 * competitor's shop window for as long as a queue takes.
 */
final class ReportContent
{
    /**
     * @throws ReportNotAllowedException when this person already has an open
     *                                   report about this
     */
    public function handle(
        User $reporter,
        Product|Review $subject,
        ReportReason $reason,
        ?string $note,
    ): Report {
        $report = new Report;

        $report->forceFill([
            'reportable_type' => $subject::class,
            'reportable_id' => $subject->getKey(),
            'user_id' => $reporter->id,
            'reason' => $reason,
            'note' => $note,
        ]);

        try {
            $report->save();
        } catch (UniqueConstraintViolationException) {
            /*
             * The partial unique index is the guard rather than a read before
             * the write, for the reason `LeaveReview` gives: two requests
             * arriving together both find nothing and both insert, and the
             * second is refused by the only party that can see both.
             *
             * It covers open reports only, so somebody whose first report was
             * dismissed may report the same thing again - it may have changed.
             */
            throw ReportNotAllowedException::alreadyReported();
        }

        $report->setRelation('reportable', $subject);

        return $report;
    }
}
