<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money that has reached a shop, pulled back (ADR 0061).
 *
 * ADR 0041 wrote the bound this lifts, in as many words: "Money that has been
 * transferred is not pulled back... Undoing a transfer is a reversal, which is
 * a different Stripe object with its own failure modes, and nothing here does
 * one." Four items across three ADRs have been waiting on it - the seller-side
 * abuse ADR 0041 named, disputes after completion (ADR 0051), appealing a
 * dispute (ADR 0059), and partial refunds (ADR 0015).
 *
 * **A reversal is recorded, never an undo.** `transferred_at`,
 * `stripe_transfer_id` and `platform_fee_minor` are left exactly as they are:
 * the transfer happened, and the fee taken is a fact about it. Clearing them
 * would be the erasure ADR 0060 was written to stop - a payment reversed three
 * times would read as one never transferred at all - and
 * `payments_transfer_is_whole` ties all three together anyway, so nulling one
 * means nulling the lot.
 *
 * **`payments_not_both_ways` is narrowed rather than dropped.** It said an
 * order is completed or cancelled and never both, which is still true of the
 * lifecycle. What it also forbade was the only sequence that can return money
 * a shop already has: reverse the transfer, then refund the buyer. Both
 * timestamps may now coexist, and only when a reversal explains why.
 *
 * It is `reversed_at` rather than a `PaymentStatus` case, for the reason ADR
 * 0041 gives about refunds: Stripe leaves the intent `succeeded` and records
 * separate objects against it, and `payments` mirrors Stripe. A status case
 * would also falsify `payments_paid_at_matches_status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // trr_..., Stripe's own id for the money coming back off the
            // connected account.
            $table->string('stripe_transfer_reversal_id')->nullable()->unique()->after('transferred_at');
            $table->timestamp('reversed_at')->nullable()->after('stripe_transfer_reversal_id');
        });

        // The same equivalence the transfer and the refund each have: a
        // reversal carries its id, and nothing else carries one.
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_reversal_is_whole
             CHECK ((reversed_at IS NULL) = (stripe_transfer_reversal_id IS NULL))'
        );

        // There is nothing to reverse that was never sent. Stripe would refuse
        // it too, and a row that said otherwise would be a row describing an
        // event that cannot have happened.
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_reversal_needs_a_transfer
             CHECK (reversed_at IS NULL OR transferred_at IS NOT NULL)'
        );

        // A reversal cannot precede the transfer it undoes.
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_reversal_after_transfer
             CHECK (reversed_at IS NULL OR reversed_at >= transferred_at)'
        );

        /*
         * Narrowed, not dropped. Transferred and refunded together is still
         * wrong on its own - it would mean a shop was paid and a buyer was made
         * whole for the same order, with nothing to say where the money came
         * from. With a reversal between them it is the only shape a
         * post-completion refund can take.
         */
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_not_both_ways');

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_not_both_ways
             CHECK (transferred_at IS NULL OR refunded_at IS NULL OR reversed_at IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_not_both_ways');

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_not_both_ways
             CHECK (transferred_at IS NULL OR refunded_at IS NULL)'
        );

        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_reversal_after_transfer');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_reversal_needs_a_transfer');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_reversal_is_whole');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['stripe_transfer_reversal_id', 'reversed_at']);
        });
    }
};
