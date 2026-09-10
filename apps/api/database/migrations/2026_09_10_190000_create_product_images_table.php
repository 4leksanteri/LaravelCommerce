<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photographs of a listing.
 *
 * On the **product**, not the variant. A sourdough in two sizes is one
 * photograph; a shirt in two colours is arguably two, and that is the case
 * variant-level images would exist for. Product-level covers the catalogue as
 * it stands, and moving down a level later is additive rather than a rewrite.
 *
 * Every row here is a WebP file this application produced. What was uploaded is
 * decoded, re-oriented, downscaled and re-encoded, and the original is never
 * stored - see ADR 0016.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $table): void {
            $table->id();

            /*
             * The public identifier, and the reason it is not the id.
             *
             * An image URL is handed out and cached, so it must not be
             * guessable: sequential ids would let anybody walk the catalogue,
             * including photographs on listings that are still drafts. A random
             * key is also exactly what object storage would give us, so moving
             * this to a bucket later changes where the bytes are and not what
             * the URL looks like.
             */
            $table->uuid()->unique();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            /*
             * Which filesystem disk holds it, stored per row rather than read
             * from config at serve time. A marketplace that moves to object
             * storage still has to serve everything uploaded before the move,
             * and a config value cannot describe two eras at once.
             */
            $table->string('disk', 40);
            $table->string('path');

            // Published so a client can reserve the right space before the
            // bytes arrive. Without them every image is a layout shift.
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('byte_size');

            // Optional, and worth having. A product photograph with no text
            // alternative is invisible to anybody using a screen reader.
            $table->string('alt_text', 200)->nullable();

            // First one is the one shown in a grid. Ordered rather than a
            // `is_primary` flag, because a flag needs a rule to keep exactly
            // one of them true and an order does not.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        // A zero-byte or zero-dimension image is a failed conversion that was
        // written anyway.
        DB::statement(
            'ALTER TABLE product_images ADD CONSTRAINT product_images_dimensions_check
             CHECK (width > 0 AND height > 0 AND byte_size > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
