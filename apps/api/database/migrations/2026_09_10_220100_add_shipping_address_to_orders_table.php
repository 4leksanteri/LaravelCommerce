<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where this particular parcel was sent.
 *
 * **A snapshot, not a reference.** There is deliberately no `address_id` here.
 * A buyer who moves house edits their address book, and every order they ever
 * placed would silently start claiming it went somewhere it did not. That is the
 * same reasoning that freezes names and prices onto `order_items` (ADR 0011),
 * applied to the one other thing a receipt has to be true about.
 *
 * It also means deleting an address is safe: nothing points at it.
 *
 * The columns are **nullable**, which is honest rather than convenient. An order
 * placed before addresses existed has none, and there is no backfill that would
 * not be an invention - nothing in a migration knows where a parcel went. There
 * happen to be zero such orders; the schema does not pretend that could not have
 * been otherwise.
 *
 * What the CHECK below rules out is the state that would actually be a bug: half
 * an address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('shipping_name')->nullable()->after('total_minor');
            $table->string('shipping_line1')->nullable()->after('shipping_name');
            $table->string('shipping_line2')->nullable()->after('shipping_line1');
            $table->string('shipping_city')->nullable()->after('shipping_line2');
            $table->string('shipping_region')->nullable()->after('shipping_city');
            $table->string('shipping_postal_code')->nullable()->after('shipping_region');
            $table->char('shipping_country', 2)->nullable()->after('shipping_postal_code');
            $table->string('shipping_phone')->nullable()->after('shipping_country');
        });

        /*
         * All four required parts arrive together or not at all.
         *
         * Written as equivalences against `line1`, so it catches both halves:
         * an address with no recipient, and a recipient with no address. The
         * genuinely optional fields - line2, region, postal code, phone - are
         * free to be null on their own, because for somewhere in the world each
         * of them legitimately is.
         */
        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_shipping_address_check CHECK (
                (shipping_line1 IS NULL) = (shipping_name IS NULL)
                AND (shipping_line1 IS NULL) = (shipping_city IS NULL)
                AND (shipping_line1 IS NULL) = (shipping_country IS NULL)
            )'
        );

        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_shipping_country_check
             CHECK (shipping_country IS NULL OR shipping_country ~ '^[A-Z]{2}$')"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_shipping_country_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_shipping_address_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'shipping_name', 'shipping_line1', 'shipping_line2', 'shipping_city',
                'shipping_region', 'shipping_postal_code', 'shipping_country', 'shipping_phone',
            ]);
        });
    }
};
