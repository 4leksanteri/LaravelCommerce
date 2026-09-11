<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who ended an order, and why (ADR 0014, ADR 0035).
 *
 * ```text
 * cancelled_by          buyer, seller, or deadline - an order nobody accepted
 * cancellation_reason   the shop's, when the shop called it off
 * completed_by          buyer, or deadline - the clock on their behalf
 * ```
 *
 * **Nullable, and the CHECKs run one way.** Every order cancelled or completed
 * from now on has an actor, and the actions that end orders always write one.
 * Orders that ended before this migration did not record who, and nothing can
 * say now - so a null means "before anybody asked", and the constraints say
 * only that an actor never appears on an order that did not end that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('cancelled_by', 20)->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->string('completed_by', 20)->nullable()->after('completed_at');
        });

        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_cancelled_by_check
             CHECK (cancelled_by IS NULL OR (status = 'cancelled' AND cancelled_by IN ('buyer', 'seller', 'deadline')))"
        );

        // A shop cannot complete an order (ADR 0012), so there is no 'seller'.
        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_completed_by_check
             CHECK (completed_by IS NULL OR (status = 'completed' AND completed_by IN ('buyer', 'deadline')))"
        );

        // A reason is the shop's to give. A buyer cancelling their own order
        // owes nobody one, and a deadline has none.
        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_cancellation_reason_check
             CHECK (cancellation_reason IS NULL OR cancelled_by = 'seller')"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_cancellation_reason_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_completed_by_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_cancelled_by_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['cancelled_by', 'cancellation_reason', 'completed_by']);
        });
    }
};
