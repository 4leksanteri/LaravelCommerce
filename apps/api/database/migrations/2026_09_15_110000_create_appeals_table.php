<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Answering back (ADR 0059).
 *
 * The platform acquired four unilateral powers in quick succession - suspending
 * a shop (ADR 0052), taking a listing down, hiding a review (ADR 0054) and
 * deciding a dispute (ADR 0051) - and both moderation ADRs closed with the same
 * open item: the subject reads why and can reply to nothing.
 *
 * **Three of the four are appealable here, and the fourth deliberately is
 * not.** A dispute is final because deciding one moves money, and reopening it
 * would need the Stripe reversal ADR 0041 does not build. What is appealable is
 * an enforcement decision that stopped something: a suspended shop, a removed
 * listing, a hidden review.
 *
 * **The morph points at the thing that was stopped, not at the report.** A
 * takedown answers a report and a suspension answers nothing, so the report is
 * not the common parent - the sanctioned thing is. It is the second polymorphic
 * table here, and the same trade the first one made: no foreign key, so the
 * subject is resolved and its absence reported rather than pretended away.
 *
 * The four decision columns are the shape `reports` and `disputes` both use,
 * and `upheld` means the same thing it means there: the case being decided was
 * right. An upheld appeal lifts the sanction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appeals', function (Blueprint $table): void {
            $table->id();

            // What was stopped: `appealable_type` and `appealable_id`.
            $table->morphs('appealable');

            // Who is answering back - the shop's owner, or a review's author.
            // Cascades, as a report does: an appeal is somebody's statement.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * **Required, unlike a report's note.** A report's reason is often
             * the whole of it - "this is counterfeit" needs no essay - but an
             * appeal with no argument is nothing for anybody to consider. The
             * floor matches a rejection's and a suspension's, and for the same
             * reason: somebody has to act on it.
             */
            $table->text('reason');

            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('upheld')->nullable();
            $table->text('outcome_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // The queue: what is still open, oldest first. Somebody whose shop
            // is stopped is losing money for every hour this waits.
            $table->index(['reviewed_at', 'id']);
        });

        /*
         * A decision is whole or it has not happened - equivalences in both
         * directions, the same shape `reports_review_is_whole` has.
         */
        DB::statement(
            'ALTER TABLE appeals ADD CONSTRAINT appeals_review_is_whole CHECK (
                (reviewed_at IS NULL) = (upheld IS NULL)
                AND (reviewed_at IS NULL) = (outcome_note IS NULL)
                AND (reviewed_at IS NULL) = (reviewed_by IS NULL)
            )'
        );

        DB::statement(
            'ALTER TABLE appeals ADD CONSTRAINT appeals_reason_not_blank
             CHECK (length(btrim(reason)) > 0)'
        );

        DB::statement(
            'ALTER TABLE appeals ADD CONSTRAINT appeals_outcome_note_not_blank
             CHECK (outcome_note IS NULL OR length(btrim(outcome_note)) > 0)'
        );

        /*
         * One **open** appeal per person per thing, enforced by the database
         * rather than by a read before the write - the reason `LeaveReview` and
         * `ReportContent` both give.
         *
         * Partial, so a decided appeal does not bar a later one: a seller who
         * has since found the paperwork has something new to say, and refusing
         * it would be the platform declining to look twice. What that does not
         * bound is somebody appealing the same decision forever, which ADR 0059
         * records rather than solves.
         */
        DB::statement(
            'CREATE UNIQUE INDEX appeals_one_open_per_appellant
             ON appeals (user_id, appealable_type, appealable_id)
             WHERE reviewed_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('appeals');
    }
};
