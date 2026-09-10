<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One line of a cart: a variant, and how many of it.
 *
 * A line references a **variant**, never a product. A product with two sizes
 * has two prices and two stock counts, so "the product, quantity 2" does not
 * name anything that can be bought (ADR 0009).
 *
 * The snapshot columns below are for display and for change detection. They
 * are **not** the price. What a line costs is read from the variant every time
 * it is shown, right up until checkout writes an order - see ADR 0010 for why
 * a cart that quotes its own stale price is worse than one that does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();

            /*
             * Nullable, and **null on delete rather than cascade**.
             *
             * A seller removing a variant would otherwise delete it out of
             * every shopper's cart, silently: the item is simply gone next
             * time they look, with nothing to explain it. Keeping the line and
             * losing only the reference lets the cart say "Rye Sourdough
             * (Large) is no longer available", which is what the snapshot
             * columns below are for.
             *
             * It is the same reasoning that makes products soft-deleted
             * (ADR 0009), one level down.
             */
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Who this line will be bought from.
             *
             * Derivable from the variant while the variant exists, which is
             * exactly the point - it is here so that a line whose variant is
             * gone can still be grouped under its shop and priced in that
             * shop's currency. A basket spanning three shops is three orders
             * in three currencies (ADR 0004), and that grouping has to survive
             * a seller tidying their catalogue.
             *
             * A product never moves between shops, so this cannot drift from
             * the variant's own seller.
             */
            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity');

            /*
             * What one cost when it went in the cart. Not what it costs now,
             * and never what is charged: it exists so the cart can say "this
             * was 24.99 when you added it". Without it that change is
             * undetectable, and a shopper discovers it at checkout.
             */
            $table->bigInteger('added_price_minor');

            // Display only, and enough of it that a line whose variant has
            // been deleted still reads as something a person recognises.
            $table->string('product_name');
            $table->string('variant_name');

            $table->timestamps();

            // Adding something already in the cart increases its quantity
            // rather than making a second line. The index is what makes that a
            // decision the schema forces rather than one a query hopes for.
            //
            // PostgreSQL treats nulls as distinct here, which is wanted: two
            // lines whose variants were both deleted are two dead lines, and
            // neither collides with the other.
            $table->unique(['cart_id', 'product_variant_id']);
        });

        // A line of zero is a line that should have been removed, and the
        // endpoints say so - DELETE removes a line, PATCH sets a quantity and
        // refuses zero. Negative is not a refund.
        DB::statement(
            'ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_check
             CHECK (quantity >= 1)'
        );

        DB::statement(
            'ALTER TABLE cart_items ADD CONSTRAINT cart_items_added_price_check
             CHECK (added_price_minor >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
