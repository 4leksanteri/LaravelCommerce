<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What is actually bought.
 *
 * Every product has at least one variant, including a product with no options
 * to choose from - that one has a single variant, created with it. The
 * alternative, price and stock on the product with variants overriding them,
 * means every read has to ask which of two prices wins, and every checkout has
 * to get that answer right. One place a price lives.
 *
 * Orders will reference a variant, never a product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // "Small", "Rye", or "Default" for a product with nothing to
            // choose. Unique within the product, so a shopper is never offered
            // two options with the same name.
            $table->string('name');

            // Integer minor units, always (ADR 0004). Named `_minor` so that
            // nothing reads it as a decimal: 2499 is 24.99 in EUR and 2499 yen
            // in JPY, and the difference is the currency's, not this column's.
            //
            // bigint rather than integer: four bytes runs out around 21
            // million euro in cents, which an aggregate reaches sooner than
            // anybody expects.
            $table->bigInteger('price_minor');

            $table->integer('stock')->default(0);

            // Sizes are S, M, L and not alphabetical. Ordering by name would
            // present them as L, M, S.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['product_id', 'name']);
            $table->index(['product_id', 'position']);
        });

        // A negative price is not a discount, it is a defect. A negative stock
        // is an oversell that already happened.
        DB::statement(
            'ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_check
             CHECK (price_minor >= 0)'
        );

        DB::statement(
            'ALTER TABLE product_variants ADD CONSTRAINT product_variants_stock_check
             CHECK (stock >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
