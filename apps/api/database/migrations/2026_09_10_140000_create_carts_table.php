<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shopper's basket.
 *
 * One row per account, and the unique index is what says so. "One active cart
 * per customer" is not a rule the application remembers to apply - there is
 * nowhere to put a second one.
 *
 * There is deliberately **no status column**. A cart with a status implies a
 * second state to be in, and the only candidate - "converted" - is a thing
 * orders do not need: an order snapshots what was agreed (root CLAUDE.md
 * section 7), so it carries its own lines and the cart is emptied. A status
 * enum with one case in use is a table waiting for a workflow that does not
 * exist.
 *
 * The table holds no data of its own beyond who it belongs to and when it last
 * changed. That is not an oversight: `updated_at` is the only cart-level fact
 * anything has needed so far, and it is the one an abandonment reminder would
 * read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->id();

            // Unique: one cart per account, enforced by the database rather
            // than by whichever code path happens to create one.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
