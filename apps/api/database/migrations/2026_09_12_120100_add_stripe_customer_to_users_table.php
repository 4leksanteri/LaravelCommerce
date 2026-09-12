<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe's handle for a buyer, so a card can be kept between orders.
 *
 * A basket spanning three shops is three PaymentIntents (ADR 0015), and the
 * buyer enters a card once: the first intent saves the method against this
 * customer, and the rest are confirmed against it without asking again. A
 * payment method cannot be reused without a customer to hold it.
 *
 * **It is an id and nothing else.** No name, no address and no card lives
 * here; what Stripe holds stays at Stripe, exactly as a shop's verification
 * does (ADR 0031). Null until somebody pays for the first time, because an
 * account that never buys anything should not appear in Stripe at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('stripe_customer_id')->nullable()->unique()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('stripe_customer_id');
        });
    }
};
