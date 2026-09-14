<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a parcel costs to send (ADR 0057).
 *
 * ADR 0011 said this column would arrive and named it: "there is still no
 * `shipping_minor`, so delivery is free and every total is the sum of its
 * lines". It also settled what happens to the total when it did - "when those
 * arrive they become their own columns and this one includes them, without the
 * name having to change". Both are followed here rather than revisited.
 *
 * **Two columns, and they are different kinds of thing.**
 *
 * ```text
 * products.shipping_minor   what this listing costs to post. A live figure the
 *                           seller edits, in the shop's currency (ADR 0009).
 * orders.shipping_minor     what was charged to post this one. A snapshot, and
 *                           never read from the catalogue again (ADR 0011).
 * ```
 *
 * `bigInteger`, as every other money column here is: integer minor units all
 * the way down (ADR 0004), and the same width as the prices it will be added
 * to.
 *
 * **Default zero, which means free shipping rather than unset.** The design
 * export shows "Free shipping" as an ordinary state of a listing, so nought is
 * a real answer and not a missing one - and it is the right default for every
 * listing that already exists, which were all sold with delivery free.
 *
 * `orders_shipping_within_total` is the invariant worth having and it dictates
 * the write order: `PlaceOrders` inserts an order with a total of nought and
 * fills it in after writing the lines, so shipping has to be set in that same
 * final write rather than at the insert. A constraint that forces the correct
 * order is doing its job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->bigInteger('shipping_minor')->default(0)->after('description');
        });

        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_shipping_minor_check
             CHECK (shipping_minor >= 0)'
        );

        Schema::table('orders', function (Blueprint $table): void {
            $table->bigInteger('shipping_minor')->default(0)->after('total_minor');
        });

        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_shipping_minor_check
             CHECK (shipping_minor >= 0)'
        );

        /*
         * The total includes the postage, so the postage cannot exceed it. It
         * is the cheapest possible statement of "total means what the buyer
         * owes", and it catches the one arithmetic mistake this change could
         * make: adding shipping to an order without adding it to the total.
         */
        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_shipping_within_total
             CHECK (shipping_minor <= total_minor)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_shipping_within_total');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_shipping_minor_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('shipping_minor');
        });

        DB::statement('ALTER TABLE products DROP CONSTRAINT products_shipping_minor_check');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('shipping_minor');
        });
    }
};
