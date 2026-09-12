<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the money went after it was held: out to the shop, or back to the buyer.
 *
 * **A refund is not a status here**, and that is deliberate. Stripe leaves the
 * PaymentIntent `succeeded` and records a separate Refund against it, because
 * the charge did succeed - returning the money is a second event, not a
 * correction of the first. This table mirrors Stripe (ADR 0031), so it says the
 * same thing: `status` stays `succeeded` and `refunded_at` says it came back.
 *
 * The two are mutually exclusive by constraint. An order is completed or
 * cancelled and never both, so money that has been transferred to a shop is
 * never also refunded from here - a transfer that had to be undone would be a
 * reversal, which nothing in this application does (ADR 0041).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            // What the marketplace kept, in minor units, recorded at the moment
            // it was taken rather than derived later from a rate that may have
            // changed since.
            $table->bigInteger('platform_fee_minor')->nullable()->after('currency');

            // tr_..., Stripe's own id for the money moving to the connected
            // account.
            $table->string('stripe_transfer_id')->nullable()->unique()->after('platform_fee_minor');
            $table->timestamp('transferred_at')->nullable()->after('stripe_transfer_id');

            // re_..., and the moment the buyer got it back.
            $table->string('stripe_refund_id')->nullable()->unique()->after('transferred_at');
            $table->timestamp('refunded_at')->nullable()->after('stripe_refund_id');
        });

        // Equivalences, so both halves hold: a transfer has its id and its fee,
        // and nothing else carries either.
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_transfer_is_whole CHECK ((transferred_at IS NULL) = (stripe_transfer_id IS NULL) AND (transferred_at IS NULL) = (platform_fee_minor IS NULL))'
        );

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_refund_is_whole CHECK ((refunded_at IS NULL) = (stripe_refund_id IS NULL))'
        );

        // An order is completed or cancelled, never both.
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_not_both_ways CHECK (transferred_at IS NULL OR refunded_at IS NULL)'
        );

        // A fee is part of what was charged, and never more than it.
        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_fee_within_amount CHECK (platform_fee_minor IS NULL OR (platform_fee_minor >= 0 AND platform_fee_minor <= amount_minor))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_fee_within_amount');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_not_both_ways');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_refund_is_whole');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_transfer_is_whole');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn([
                'platform_fee_minor',
                'stripe_transfer_id',
                'transferred_at',
                'stripe_refund_id',
                'refunded_at',
            ]);
        });
    }
};
