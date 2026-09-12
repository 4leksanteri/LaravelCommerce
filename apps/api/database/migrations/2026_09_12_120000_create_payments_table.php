<?php

declare(strict_types=1);

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What was charged for one order, and where that charge got to.
 *
 * **One row per order**, because there is one PaymentIntent per order: a
 * basket spanning three shops is three orders in three currencies, and a
 * PaymentIntent has exactly one currency (ADR 0015). The unique index on
 * `order_id` is what makes "one" true rather than intended.
 *
 * The amount and currency are copied from the order rather than read through
 * it. They are what was actually sent to Stripe, and a receipt should not
 * depend on the order's own figures staying untouched - the same reason the
 * order snapshots its prices from the catalogue (ADR 0011).
 *
 * This table holds no card details and never will. The payment method id is
 * Stripe's handle for a card it holds, which is what lets the rest of a
 * basket be confirmed without asking for the card again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();

            // Restricted rather than cascading: a paid order is a financial
            // record, and deleting one should fail loudly rather than take its
            // payment with it.
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();

            // pi_..., Stripe's own id. Unique, so a retry that somehow created
            // a second intent cannot be recorded against the same order twice.
            $table->string('stripe_payment_intent_id')->unique();

            /*
             * The handle the buyer's browser confirms this intent with.
             *
             * Stored rather than fetched, because the alternative is a call to
             * Stripe for every unpaid order every time a page is drawn. It is
             * not a credential of ours: it authorises exactly one intent, is
             * useless without the publishable key, and is shown only to the
             * buyer whose order it is.
             */
            $table->string('stripe_client_secret')->nullable();

            $table->string('status', 20)->default(PaymentStatus::Pending->value);

            $table->bigInteger('amount_minor');
            $table->char('currency', 3);

            // pm_..., the card Stripe kept. Null until something is paid with
            // it, and the handle the other orders in the same basket are
            // charged against off-session.
            $table->string('stripe_payment_method_id')->nullable();

            // Stripe's own words for a refusal, shown to the buyer as sent.
            $table->text('failure_reason')->nullable();

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // Every payment for one basket, which is how the confirmation page
            // asks what is still outstanding.
            $table->index(['status']);
        });

        DB::statement(
            'ALTER TABLE payments ADD CONSTRAINT payments_amount_not_negative CHECK (amount_minor >= 0)'
        );

        // An equivalence rather than one implication, so both halves hold: a
        // succeeded payment has its date, and nothing else carries one.
        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT payments_paid_at_matches_status CHECK ((status = 'succeeded') = (paid_at IS NOT NULL))"
        );

        // A refusal has a reason, and only a refusal does.
        DB::statement(
            "ALTER TABLE payments ADD CONSTRAINT payments_failure_reason_only_when_failed CHECK (status = 'failed' OR failure_reason IS NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
