<?php

declare(strict_types=1);

use App\Enums\Carrier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who is carrying the parcel, and under what number (ADR 0049).
 *
 * ADR 0019 listed shipping as one of four things the design export showed with
 * nothing behind it: "`shipped_at` and nothing else - no carrier, no tracking
 * number". `ShipOrder` said the same in its own words, and pointed at the
 * missing delivery address as the reason. Addresses arrived with ADR 0021, so
 * this is the other half of that sentence.
 *
 * **Both are optional**, and that is the decision the rest follows from. A
 * seller posting an untracked letter must still be able to mark the order sent;
 * requiring a number would either block them or teach them to invent one, and
 * an invented tracking number is worse than none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Beside the date it was sent, because the three describe one event.
            $table->string('carrier', 20)->nullable()->after('shipped_at');
            $table->string('tracking_number', 64)->nullable()->after('carrier');
        });

        // The same shape as `orders_status_check`: the enum is the definition,
        // and the database refuses anything the application would not write.
        DB::statement(sprintf(
            "ALTER TABLE orders ADD CONSTRAINT orders_carrier_check
             CHECK (carrier IS NULL OR carrier IN ('%s'))",
            implode("', '", Carrier::values()),
        ));

        /*
         * Neither can exist on an order that was never sent.
         *
         * The timeline constraint already says which timestamps each status may
         * have; this says the same about the shipment's details, so an order
         * that is still pending cannot carry a tracking number left behind by
         * something that was undone.
         */
        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_tracking_needs_shipping
             CHECK (shipped_at IS NOT NULL OR (carrier IS NULL AND tracking_number IS NULL))'
        );

        // A carrier with nothing to track is a label doing no work, and it would
        // publish a link to a search for an empty string.
        DB::statement(
            'ALTER TABLE orders ADD CONSTRAINT orders_carrier_needs_a_number
             CHECK (carrier IS NULL OR (tracking_number IS NOT NULL AND length(btrim(tracking_number)) > 0))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_carrier_needs_a_number');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_tracking_needs_shipping');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_carrier_check');

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['carrier', 'tracking_number']);
        });
    }
};
