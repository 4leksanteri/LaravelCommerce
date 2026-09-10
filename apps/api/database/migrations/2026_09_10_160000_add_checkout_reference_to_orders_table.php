<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which orders were one checkout.
 *
 * A basket spanning three shops becomes three orders (ADR 0011), and until now
 * those three were indistinguishable from three separate purchases. Only the
 * buyer and three timestamps a few milliseconds apart connected them, which is
 * a guess rather than a fact.
 *
 * **This is recorded now because it cannot be recorded later.** Every other
 * column on this table can be added with a sensible default and backfilled; a
 * fact about an event that has already happened cannot. The alternative was to
 * infer it afterwards from timestamp proximity - which would work, right up
 * until an import or a slow transaction made it not.
 *
 * A shared reference rather than a `checkouts` table and a foreign key. Such a
 * table would hold `user_id` and `created_at`, and both are already on every
 * order it would own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Nullable to begin with, so the column can be added to a table
            // that already has rows in it.
            $table->string('checkout_reference', 16)->nullable()->after('reference');
        });

        // An order placed before this column existed becomes its own checkout,
        // because that is the most that can honestly be said about it. There
        // are none - orders were added in the commit before this one - and the
        // statement is here so the migration is correct if that ever stops
        // being true.
        DB::statement('UPDATE orders SET checkout_reference = reference WHERE checkout_reference IS NULL');

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('checkout_reference', 16)->nullable(false)->change();

            // Deliberately not unique: sharing it is the entire point. Indexed
            // because every use is "the other orders with this one".
            $table->index('checkout_reference');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['checkout_reference']);
            $table->dropColumn('checkout_reference');
        });
    }
};
