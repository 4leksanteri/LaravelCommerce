<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When a shipped order completes on its own, and how many times the buyer has
 * pushed that back.
 *
 * The date is **stored rather than computed** from `shipped_at` plus a window,
 * for two reasons. It has to move when a buyer says their parcel is late, and
 * both parties should be able to see it - "completes automatically on the 24th"
 * is a thing to tell somebody, and a window in a config file is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->timestamp('auto_complete_at')->nullable()->after('shipped_at');

            // How many times the buyer has pushed the date back. Capped, so a
            // shipment that never arrives cannot be deferred forever - what
            // happens after the cap is a dispute, and there are none.
            $table->unsignedInteger('completion_extensions')->default(0)->after('auto_complete_at');
        });

        // An order has a completion deadline exactly when it has shipped.
        //
        // Written as an equivalence and kept out of the status CASE in
        // `orders_timeline_check`, because it holds across three statuses: a
        // shipped order has one, a completed order still records the one it
        // had, and a shipped order the seller then cancelled keeps it too.
        // "Has it shipped" is the only question that matters.
        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_auto_complete_at_check
             CHECK ((shipped_at IS NULL) = (auto_complete_at IS NULL))'
        );

        // Nothing is extended before it ships.
        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_completion_extensions_check
             CHECK (completion_extensions = 0 OR shipped_at IS NOT NULL)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_completion_extensions_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_auto_complete_at_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['auto_complete_at', 'completion_extensions']);
        });
    }
};
