<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the platform has decided about a shop (ADR 0060).
 *
 * Four ADRs closed with the same open item in near-identical words: a history
 * of suspensions (ADR 0052), a history of what a shop has had taken down
 * (ADR 0054), a history of decisions (ADR 0051) and a history of appeals
 * (ADR 0059). Three of the four say why in the same sentence - it is "exactly
 * what somebody deciding whether to suspend the shop would want".
 *
 * **It cannot be derived from what is stored, and that is why this table
 * exists.** Reversing a sanction erases it:
 *
 * ```text
 * ReinstateShop      nulls suspended_at, suspension_reason, suspended_by
 * DecideAppeal::lift nulls removed_at, removal_reason, removed_by
 * ```
 *
 * and in both cases a CHECK constraint - `sellers_suspension_is_whole`,
 * `products_removal_is_whole` - ties those columns to the status as
 * equivalences, so a trading shop and a restored listing are *forbidden* from
 * keeping them. A shop suspended three times and reinstated three times looks
 * exactly like a shop that has never been stopped. The rows here are written
 * when the decision is taken and never touched again.
 *
 * **Append-only.** There is no `updated_at`, no endpoint that edits one and no
 * endpoint that deletes one. A record somebody can revise is not a record.
 *
 * **It is the shop's record, and a hidden review is deliberately not in it.**
 * Hiding a review is a decision about a buyer's words, not about the shop whose
 * listing they were left on. Folding those in would show somebody weighing a
 * suspension three strikes that the shop's own customers had earned, which is
 * worse than showing them nothing. That leaves review takedowns and review
 * appeals unrecorded, which is honest for the same reason `stripe_events` only
 * keeps what it acts on: a table of events nobody reads is not an audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_decisions', function (Blueprint $table): void {
            $table->id();

            /*
             * The shop it counts against, and never null.
             *
             * That is the scoping decision rather than a convenience: what is
             * recorded here is what somebody reads when weighing this shop, so
             * a decision with no shop to count against has no reader and is not
             * written at all.
             */
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->string('kind');

            /*
             * What it was about: the shop, a listing, a dispute or an appeal.
             *
             * The third morph here, and it makes the same trade as the first
             * two: no foreign key, so a seller deleting a listing leaves a row
             * pointing at nothing. The resource reports that rather than
             * pretending otherwise - and a shop deleting what it was punished
             * for is itself worth seeing.
             *
             * It is redundant for a suspension, where the subject is the shop
             * named beside it. Kept anyway, because a record whose shape
             * changes per kind is one every reader has to special-case.
             */
            $table->morphs('subject');

            /*
             * The words given at the time: the suspension reason, the note that
             * took a listing down, how a dispute was reasoned, what was said
             * about an appeal.
             *
             * Null where the decision carried none - lifting a suspension is
             * not currently asked for a reason - rather than an empty string
             * standing in for one.
             */
            $table->text('reason')->nullable();

            /*
             * Who decided, recorded and **not published**, for the reason a
             * dispute's `resolved_by` and a suspension's `suspended_by` are
             * not: the decision is the platform's rather than an individual's,
             * and naming somebody invites the complaint to follow them.
             *
             * Nullable only so that closing a staff account does not take the
             * record with it (ADR 0058).
             */
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();

            // No `updated_at`: nothing edits one of these.
            $table->timestamp('created_at');

            // The read this exists for: one shop's record, newest first.
            $table->index(['seller_id', 'id']);
        });

        DB::statement(
            'ALTER TABLE platform_decisions ADD CONSTRAINT platform_decisions_reason_not_blank
             CHECK (reason IS NULL OR length(btrim(reason)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_decisions');
    }
};
