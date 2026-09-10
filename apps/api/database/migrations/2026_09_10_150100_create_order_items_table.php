<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A line of an order, and the whole point of orders existing.
 *
 * **Everything here is a snapshot, and it is authoritative.** A cart line reads
 * its price from the variant every time it is shown (ADR 0010); an order line
 * never does. What the buyer agreed to is what is written here, and the
 * catalogue may afterwards change the name, move the price or delete the
 * listing without touching a single figure on a receipt.
 *
 * That is the line ADR 0010 drew and this is the other side of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            /*
             * A link back to the catalogue, and nothing more than a link.
             *
             * Nullable and nulled on delete for the same reason a cart line's
             * is (ADR 0010): a seller removing a variant must not reach into
             * somebody's order history. Nothing on this row is read through it
             * - it exists so a buyer can be offered "order this again", and its
             * absence costs a link rather than a fact.
             */
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // What it was called when it was bought. There are no structured
            // variant options yet - a variant has a name - so there is one
            // column rather than a JSON blob nothing would put anything in.
            $table->string('product_name');
            $table->string('variant_name');

            // Integer minor units, in the order's currency (ADR 0004). The
            // currency lives on the order because every line of one order is in
            // it by construction.
            $table->bigInteger('unit_price_minor');

            $table->unsignedInteger('quantity');

            $table->timestamps();

            // A line total is deliberately **not** stored. It is
            // `unit_price_minor * quantity` exactly, and a stored copy is a
            // second version of a number that can drift from the two it came
            // from. The order's total is stored, because that is the figure a
            // payment is made against.

            $table->index('order_id');
        });

        DB::statement(
            'ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check
             CHECK (quantity >= 1)'
        );

        DB::statement(
            'ALTER TABLE order_items ADD CONSTRAINT order_items_unit_price_check
             CHECK (unit_price_minor >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
