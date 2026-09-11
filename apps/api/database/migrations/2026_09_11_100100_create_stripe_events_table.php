<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Stripe events this application has already acted on.
 *
 * Stripe delivers at least once: a webhook that timed out, or that answered
 * after Stripe had given up waiting, is sent again. The primary key is the
 * whole mechanism - a second delivery of the same event cannot insert, so it
 * cannot be handled twice (ADR 0015).
 *
 * Only events that are acted on are recorded. Everything else is acknowledged
 * and forgotten, and a table of events nobody reads is not an audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_events', function (Blueprint $table): void {
            // evt_..., Stripe's own id.
            $table->string('id')->primary();
            $table->string('type');
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_events');
    }
};
