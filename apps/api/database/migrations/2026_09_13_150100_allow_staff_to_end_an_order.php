<?php

declare(strict_types=1);

use App\Enums\OrderActor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The platform becomes a third thing that can end an order.
 *
 * `OrderParty` said this would happen: "Platform staff are deliberately absent.
 * Nothing gives staff a way to move somebody else's order, and the day
 * something does it will be a third case here with its own rules rather than a
 * seller impersonation." Resolving a dispute is that day - though the case
 * lands on `OrderActor` rather than `OrderParty`, because staff end an order
 * without ever becoming a side of it.
 *
 * **It is a case rather than a reuse of `deadline`.** A dispute decided by a
 * person is not a clock running out, and recording it as one would make the
 * question "who ended this order" unanswerable exactly where it matters most -
 * on the orders somebody complained about.
 *
 * The rules stay asymmetric where they were. A shop still cannot complete an
 * order, because completion releases money to it; `completed_by` gains `staff`
 * and still refuses `seller`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_cancelled_by_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_completed_by_check');

        DB::statement(sprintf(
            "ALTER TABLE orders ADD CONSTRAINT orders_cancelled_by_check
             CHECK (cancelled_by IS NULL OR (status = 'cancelled' AND cancelled_by IN ('%s')))",
            implode("', '", array_column(OrderActor::cases(), 'value')),
        ));

        // Deliberately not every case: a seller completing their own order is
        // the one thing this marketplace exists to prevent (ADR 0012).
        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_completed_by_check
             CHECK (completed_by IS NULL OR (status = 'completed' AND completed_by IN ('buyer', 'deadline', 'staff')))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_completed_by_check');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_cancelled_by_check');

        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_cancelled_by_check
             CHECK (cancelled_by IS NULL OR (status = 'cancelled' AND cancelled_by IN ('buyer', 'seller', 'deadline')))"
        );

        DB::statement(
            "ALTER TABLE orders ADD CONSTRAINT orders_completed_by_check
             CHECK (completed_by IS NULL OR (status = 'completed' AND completed_by IN ('buyer', 'deadline')))"
        );
    }
};
