<?php

declare(strict_types=1);

use App\Enums\DisputeResolution;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What happens when the two sides disagree about what arrived.
 *
 * ADR 0012, ADR 0014 and ADR 0041 each stopped at this table. Cancelling a
 * shipped order, extending past the cap and unwinding a completed one are the
 * same question, and until now nothing answered it.
 *
 * **A dispute exists while the money is held, and only then.** `isHeld()` on
 * the payment - paid, not transferred, not refunded - is what both money
 * actions already gate on, and it is the whole window in which a decision can
 * still send the money either way. Once a transfer has gone, pulling it back
 * is a Stripe reversal, which ADR 0041 deliberately does not build.
 *
 * **One per order, for good.** Resolving one ends the order: refunded cancels
 * it, released completes it, and both are final. So a second dispute on the
 * same order is not a state this domain can reach, and the unique index says
 * so rather than leaving it to the code that happens to write them.
 *
 * The reason is the buyer's own words and is shown to the shop. The resolution
 * note is the platform's, and is shown to both - a decision about somebody's
 * money that arrives without a reason is the kind support tickets are made of,
 * which is why `sellers.rejection_reason` is required too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table): void {
            $table->id();

            // Restricted rather than cascaded, as a payment's order is: this is
            // the record of where somebody's money went, and deleting the order
            // should fail loudly rather than quietly take the evidence with it.
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();

            $table->text('reason');

            // All four are null together while it is open, and set together
            // when it is decided. The constraint below is what makes that true.
            $table->string('resolution', 20)->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();

            // Who decided. Nullable because a member of staff can leave, and
            // losing their account must not take the decision with it.
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // How the queue is read: what is still open, oldest first, so the
            // person who has waited longest is dealt with first.
            $table->index(['resolved_at', 'id']);
        });

        DB::statement(sprintf(
            "ALTER TABLE disputes ADD CONSTRAINT disputes_resolution_check
             CHECK (resolution IS NULL OR resolution IN ('%s'))",
            implode("', '", DisputeResolution::values()),
        ));

        /*
         * A decision is whole or it has not happened.
         *
         * Written as equivalences rather than as three separate rules, so it
         * catches both halves: a resolution with no date, and a date with no
         * resolution, are each impossible.
         */
        DB::statement(
            'ALTER TABLE disputes ADD CONSTRAINT disputes_resolution_is_whole CHECK (
                (resolved_at IS NULL) = (resolution IS NULL)
                AND (resolved_at IS NULL) = (resolution_note IS NULL)
                AND (resolved_at IS NULL) = (resolved_by IS NULL)
            )'
        );

        // Somebody has to say something, both when opening one and when
        // deciding it. The same rule `order_messages_body_not_blank` states.
        DB::statement(
            'ALTER TABLE disputes ADD CONSTRAINT disputes_reason_not_blank
             CHECK (length(btrim(reason)) > 0)'
        );

        DB::statement(
            'ALTER TABLE disputes ADD CONSTRAINT disputes_note_not_blank
             CHECK (resolution_note IS NULL OR length(btrim(resolution_note)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
