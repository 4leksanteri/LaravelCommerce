<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What staff can do about one listing or one review (ADR 0054).
 *
 * Until now the only tool against a single bad thing was suspending the whole
 * shop (ADR 0052), which is the sentence that ADR listed as its own next
 * chapter.
 *
 * **A takedown is not a soft delete, and not an unpublish.** `products.deleted_at`
 * is the seller's own removal - the column says so: "delete from a seller's
 * point of view means take it out of my shop". And `products_published_at_check`
 * ties `published_at` to the status as an equivalence, so a takedown cannot be
 * expressed by clearing a date. It needs its own columns, and it needs to be
 * **sticky**: without that the seller republishes a minute later, which is
 * exactly the hole suspension had to close for shops.
 *
 * **A hidden review is not deleted either.** ADR 0047 refused deletion because
 * "who may erase one is an argument between two parties this codebase cannot
 * hear" - the platform is the third party it was missing, and hiding is what it
 * does. The row stays, so the author still cannot leave a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('removed_at')->nullable()->after('published_at');

            // The seller reads this. A listing taken down with no reason is a
            // shop owner with nothing to fix and nothing to appeal.
            $table->text('removal_reason')->nullable()->after('removed_at');

            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_removal_is_whole CHECK (
                (removed_at IS NULL) = (removal_reason IS NULL)
                AND (removed_at IS NULL) = (removed_by IS NULL)
            )'
        );

        DB::statement(
            'ALTER TABLE products ADD CONSTRAINT products_removal_reason_not_blank
             CHECK (removal_reason IS NULL OR length(btrim(removal_reason)) > 0)'
        );

        /*
         * A listing the platform removed is not on sale. Enforced here as well
         * as in `PublishProduct`, because the action is what gives a seller an
         * answer and this is what makes the state impossible - the same pairing
         * `PublishProduct` describes for an unapproved shop.
         */
        DB::statement(
            "ALTER TABLE products ADD CONSTRAINT products_removed_is_not_published
             CHECK (removed_at IS NULL OR status <> 'published')"
        );

        Schema::table('reviews', function (Blueprint $table): void {
            $table->timestamp('hidden_at')->nullable()->after('body');
            $table->text('hidden_reason')->nullable()->after('hidden_at');
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::statement(
            'ALTER TABLE reviews ADD CONSTRAINT reviews_hiding_is_whole CHECK (
                (hidden_at IS NULL) = (hidden_reason IS NULL)
                AND (hidden_at IS NULL) = (hidden_by IS NULL)
            )'
        );

        DB::statement(
            'ALTER TABLE reviews ADD CONSTRAINT reviews_hidden_reason_not_blank
             CHECK (hidden_reason IS NULL OR length(btrim(hidden_reason)) > 0)'
        );

        // How a listing's visible reviews are read, and how the rating
        // aggregate counts them: its own, still showing, newest first.
        Schema::table('reviews', function (Blueprint $table): void {
            $table->index(['product_id', 'hidden_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropIndex(['product_id', 'hidden_at', 'id']);
        });

        DB::statement('ALTER TABLE reviews DROP CONSTRAINT reviews_hidden_reason_not_blank');
        DB::statement('ALTER TABLE reviews DROP CONSTRAINT reviews_hiding_is_whole');

        Schema::table('reviews', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hidden_by');
            $table->dropColumn(['hidden_at', 'hidden_reason']);
        });

        DB::statement('ALTER TABLE products DROP CONSTRAINT products_removed_is_not_published');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_removal_reason_not_blank');
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_removal_is_whole');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('removed_by');
            $table->dropColumn(['removed_at', 'removal_reason']);
        });
    }
};
