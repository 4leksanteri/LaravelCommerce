<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closing an account (ADR 0058).
 *
 * **One nullable timestamp, and deliberately not `deleted_at`.** Laravel's
 * `SoftDeletes` reads that name and adds a global scope, and a global scope is
 * exactly the wrong thing here: it would hide the row from every relation, so
 * an order's buyer would resolve to null and a shop's receipt would stop naming
 * who it shipped to. The row has to stay readable and be stripped instead.
 *
 * **There is no row to delete anyway.** `orders.user_id` and `orders.seller_id`
 * are `restrictOnDelete`, so the database refuses to remove anybody who has
 * ever bought or sold - which is ADR 0011's "a receipt has to outlive the
 * account that paid it" enforced rather than described. Closing is therefore
 * anonymisation, and this column is the record that it happened.
 *
 * Nullable rather than defaulted, because the overwhelming majority of accounts
 * are open and a date is the wrong shape for "no".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('closed_at');
        });
    }
};
