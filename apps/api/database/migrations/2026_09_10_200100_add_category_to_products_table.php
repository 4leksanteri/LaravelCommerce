<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One category per listing.
 *
 * Not many-to-many, deliberately. A product in four categories has no
 * unambiguous breadcrumb and no obvious place to appear, and the flexibility
 * buys nothing a shopper can perceive. It is also how sellers think about their
 * own stock: this is a bread, not a bread-and-gift-and-seasonal.
 *
 * **Nullable in the column, required to publish.** A draft can be anything -
 * somebody typing up a listing has not decided yet - but a published one has to
 * be findable, and a listing in no category is in no navigation. That is the
 * same shape as needing an approved shop to publish (ADR 0009): drafting is
 * free, going on sale has conditions.
 *
 * restrictOnDelete, because `DeleteCategory` refuses while anything references
 * it. This is the backstop if that check is ever bypassed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('seller_id')
                ->constrained()->restrictOnDelete();

            // The storefront asks "published things in this category", and the
            // marketplace-wide category listing asks it without a shop.
            $table->index(['category_id', 'status']);
        });

        /*
         * **This unpublishes listings that cannot satisfy the new rule.**
         *
         * Every product published before this migration has no category, and
         * the constraint below would refuse the whole migration on the first
         * one. There is no sensible backfill - nothing here knows what a
         * listing is - so they go back to draft with their sellers' own
         * catalogues intact, and publishing again is one request once a
         * category is chosen.
         *
         * Called out rather than done quietly: it changes what is on sale.
         */
        DB::statement(
            "UPDATE products SET status = 'draft', published_at = NULL
             WHERE status = 'published' AND category_id IS NULL"
        );

        // Published exactly when findable. `PublishProduct` gives the seller an
        // answer; this is what holds if anything ever writes the column
        // directly, which is the difference between a rule and a habit.
        DB::statement(
            "ALTER TABLE products ADD CONSTRAINT products_published_category_check
             CHECK (status <> 'published' OR category_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_published_category_check');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['category_id', 'status']);
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
