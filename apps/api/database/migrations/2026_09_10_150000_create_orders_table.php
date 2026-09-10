<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What was agreed.
 *
 * **One order per shop.** A basket spanning three shops becomes three orders,
 * because each is an agreement with a different seller, denominated in that
 * seller's currency, and settled by a different payment (ADR 0004, ADR 0011).
 * There is no row here that spans two of them, and no total that could.
 *
 * Everything an order says about what was bought is a snapshot, held on
 * `order_items`. The catalogue is free to move afterwards; a receipt is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();

            /*
             * What a person quotes.
             *
             * The integer id is not it. A sequential number in a URL publishes
             * how many orders the marketplace has taken, and invites somebody
             * to walk it - refused, but asked. This is also the sort of column
             * that is painful to add later, because every order already placed
             * needs one backfilled.
             */
            $table->string('reference', 16)->unique();

            /*
             * restrictOnDelete on both, deliberately, where the rest of this
             * schema cascades.
             *
             * Deleting an account must not quietly destroy a seller's record of
             * what they sold, and deleting a shop must not destroy what its
             * buyers paid for. Erasing somebody is a real question - it is just
             * not one a foreign key should answer on its own.
             */
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('seller_id')->constrained()->restrictOnDelete();

            $table->string('status', 20)->default(OrderStatus::Pending->value);

            /*
             * Snapshotted from the shop, and this is a different answer from
             * the one products got.
             *
             * A product has no currency column because it would be a second
             * copy of the shop's that could disagree (ADR 0009). An order
             * records what was agreed, and must not depend on the shop's
             * currency staying what it was - it is fixed today, and "today"
             * is not a guarantee a receipt should rest on.
             */
            $table->string('currency', 3);

            /*
             * What the buyer owes, in minor units of the currency above.
             *
             * Equal to the sum of the lines today because there is no shipping
             * and no tax. When those arrive they become their own columns and
             * this one includes them, which is why it is not called `subtotal`.
             */
            $table->bigInteger('total_minor');

            $table->timestamps();

            // Every read is "this person's orders, newest first" or "this
            // shop's orders, newest first".
            $table->index(['user_id', 'created_at']);
            $table->index(['seller_id', 'created_at']);
        });

        DB::statement(sprintf(
            "ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('%s'))",
            implode("', '", OrderStatus::values()),
        ));

        // A free order is possible; a negative one is a defect.
        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_total_check CHECK (total_minor >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
