<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order lifecycle: four more statuses, and the timestamps that record when
 * each was reached.
 *
 * These are the `accepted_at` and `shipped_at` columns that were proposed for
 * products and refused there (ADR 0009) - they describe an order's life, and
 * this is the table that has one.
 *
 * The state machine is written into the database as a CHECK constraint rather
 * than left to the application. An order whose status says shipped and whose
 * `shipped_at` is null is not a state any code path should be able to produce,
 * and the only layer that holds that under concurrency is this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Nullable, and set once, by the transition that reaches them. They
            // are not cleared afterwards: a completed order still records when
            // it was accepted and when it shipped.
            $table->timestamp('accepted_at')->nullable()->after('status');
            $table->timestamp('shipped_at')->nullable()->after('accepted_at');
            $table->timestamp('completed_at')->nullable()->after('shipped_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
        });

        // The old constraint allowed one value. Dropped and rewritten rather
        // than added to, because a CHECK cannot be extended in place.
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');

        DB::statement(sprintf(
            "ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('%s'))",
            implode("', '", OrderStatus::values()),
        ));

        /*
         * The whole state machine, in one constraint.
         *
         * Each status says exactly which timestamps must be set and which must
         * not, so the two cannot disagree - no shipped order without a shipping
         * date, and no date left behind on something that was cancelled before
         * it got there.
         *
         * `ELSE false` is deliberate and it fails closed. Adding a status to
         * the enum without teaching this constraint about it makes every write
         * of that status fail loudly, rather than silently skipping the check
         * for exactly the state nobody has thought about yet.
         */
        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_timeline_check CHECK (
                CASE status
                    WHEN 'pending' THEN
                        accepted_at IS NULL AND shipped_at IS NULL
                        AND completed_at IS NULL AND cancelled_at IS NULL
                    WHEN 'accepted' THEN
                        accepted_at IS NOT NULL AND shipped_at IS NULL
                        AND completed_at IS NULL AND cancelled_at IS NULL
                    WHEN 'shipped' THEN
                        accepted_at IS NOT NULL AND shipped_at IS NOT NULL
                        AND completed_at IS NULL AND cancelled_at IS NULL
                    WHEN 'completed' THEN
                        accepted_at IS NOT NULL AND shipped_at IS NOT NULL
                        AND completed_at IS NOT NULL AND cancelled_at IS NULL
                    WHEN 'cancelled' THEN
                        cancelled_at IS NOT NULL AND completed_at IS NULL
                    ELSE false
                END
            )"
        );

        Schema::table('orders', function (Blueprint $table): void {
            // A seller's queue is "what needs doing", which is a status filter
            // over one shop.
            $table->index(['seller_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['seller_id', 'status']);
        });

        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_timeline_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_status_check');

        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending'))"
        );

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['accepted_at', 'shipped_at', 'completed_at', 'cancelled_at']);
        });
    }
};
