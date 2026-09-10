<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('seller_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // The product's address within its shop, so the public URL reads
            // /shops/koskela-bake-house/products/rye-sourdough. Unique per
            // shop rather than globally: two shops may both sell a "Rye
            // sourdough" and neither should have to call theirs rye-2.
            $table->string('slug');

            $table->text('description')->nullable();

            $table->string('status', 20)->default(ProductStatus::Draft->value);

            // Set when published, cleared when unpublished, with a check
            // constraint below tying the two together. It is "currently
            // published since", not "first ever published" - the latter would
            // be a different column with a different name.
            $table->timestamp('published_at')->nullable();

            // Soft delete. A hard delete would orphan the order history that
            // will eventually point here, and "delete" from a seller's point
            // of view means "take it out of my shop" rather than "erase it
            // from what people bought".
            $table->softDeletes();

            $table->timestamps();

            $table->unique(['seller_id', 'slug']);

            // The storefront reads published products of one shop constantly.
            $table->index(['seller_id', 'status']);
        });

        // There is deliberately no `currency` column. A shop's currency is
        // fixed (ADR 0007) and a product belongs to exactly one shop, so a
        // column here would be a second copy that can disagree with the first.
        // A price is in its seller's currency, and that is the only answer.

        DB::statement(sprintf(
            "ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('%s'))",
            implode("', '", ProductStatus::values()),
        ));

        // Published exactly when there is a publication date. Written as an
        // equivalence so it catches both halves: a published product with no
        // date, and a date left behind on something unpublished.
        DB::statement(
            "ALTER TABLE products ADD CONSTRAINT products_published_at_check
             CHECK ((status = 'published') = (published_at IS NOT NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
