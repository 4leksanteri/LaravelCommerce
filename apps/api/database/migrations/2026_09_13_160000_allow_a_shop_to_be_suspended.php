<?php

declare(strict_types=1);

use App\Enums\SellerStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shop that has been trading can be stopped (ADR 0052).
 *
 * `SellerStatus` said this would happen. It used to end "there is deliberately
 * no Suspended case yet - suspending a trading shop raises questions about open
 * orders and pending payouts that have no answer until those exist", and
 * orders, payouts and disputes now all exist.
 *
 * **Nothing else has to change for it to work.** `Seller::scopePublic()` asks
 * for `status = approved` rather than for "not rejected", so a fourth status
 * removes the shop from the storefront, its listings from search and browse,
 * its photographs from the image route, and its ability to publish anything -
 * all from this one case. That is what the enum's docblock means by there being
 * no second `is_public` flag to fall out of step.
 *
 * **The reason is its own column rather than `rejection_reason`.** They are
 * different events at different points in a shop's life, and
 * `sellers_rejection_reason_check` is written about a rejection; storing a
 * suspension in it would make `SellerResource`'s comment that the column is
 * "only ever set on a rejection" untrue.
 *
 * The existing `sellers_reviewed_at_check` needs no change: it says a pending
 * row is the unreviewed one, and a suspended shop was approved before it was
 * suspended, so it already carries a review date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sellers', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->after('reviewed_by');

            // Shown to the shop, and to staff looking at the queue. Required
            // while a suspension stands, for the reason a rejection's is: a
            // shop told to stop with no reason cannot fix anything.
            $table->text('suspension_reason')->nullable()->after('suspended_at');

            // Who stopped it. Recorded rather than published, as a dispute's
            // `resolved_by` is: stopping somebody's business is the decision in
            // this application most worth being able to account for later, and
            // `reviewed_by` cannot carry it - that is the original approval,
            // and overwriting it would lose the fact that the shop was ever
            // approved at all.
            $table->foreignId('suspended_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Rebuilt from the enum, which now has a fourth case. The original
        // constraint was written the same way, so this is the same statement
        // over a longer list.
        DB::statement('ALTER TABLE sellers DROP CONSTRAINT sellers_status_check');

        DB::statement(sprintf(
            "ALTER TABLE sellers ADD CONSTRAINT sellers_status_check CHECK (status IN ('%s'))",
            implode("', '", SellerStatus::values()),
        ));

        /*
         * A suspension is whole, or it has not happened.
         *
         * Written as equivalences in both directions, so all three of the
         * states that would be wrong are refused: a suspended shop with no
         * date, a date on a shop that is trading, and a reason with nothing to
         * explain. Reinstating clears both columns, which is what keeps the
         * first equivalence true.
         */
        DB::statement(
            "ALTER TABLE sellers ADD CONSTRAINT sellers_suspension_is_whole CHECK (
                (status = 'suspended') = (suspended_at IS NOT NULL)
                AND (suspended_at IS NULL) = (suspension_reason IS NULL)
                AND (suspended_at IS NULL) = (suspended_by IS NULL)
            )"
        );

        DB::statement(
            'ALTER TABLE sellers ADD CONSTRAINT sellers_suspension_reason_not_blank
             CHECK (suspension_reason IS NULL OR length(btrim(suspension_reason)) > 0)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE sellers DROP CONSTRAINT sellers_suspension_reason_not_blank');
        DB::statement('ALTER TABLE sellers DROP CONSTRAINT sellers_suspension_is_whole');
        DB::statement('ALTER TABLE sellers DROP CONSTRAINT sellers_status_check');

        DB::statement(
            "ALTER TABLE sellers ADD CONSTRAINT sellers_status_check
             CHECK (status IN ('pending', 'approved', 'rejected'))"
        );

        Schema::table('sellers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('suspended_by');
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
