<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody who bought a thing thought of it.
 *
 * **A review belongs to the listing, not to the line that bought it.** An order
 * line points at a variant and that pointer nulls when the seller deletes it
 * (ADR 0011), so a review hung off one would lose its subject the day somebody
 * tidied their catalogue. A review is about the thing, and outlives whichever
 * option the buyer happened to choose.
 *
 * **The entitling order is kept rather than re-derived.** Whether somebody may
 * review is answered once, when they write it, from a completed order of their
 * own; storing which order that was means a seller cannot make a standing
 * review look unearned by changing the catalogue underneath it.
 *
 * One row per buyer per listing. Buying the same thing twice does not buy a
 * second voice, and the unique index is what makes that true of the data rather
 * than of the code that happens to write it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Restricted, as a payment's order is: this is the evidence that the
            // review was earned, and deleting the order should fail loudly
            // rather than quietly take the proof with it.
            $table->foreignId('order_id')->constrained()->restrictOnDelete();

            $table->unsignedTinyInteger('rating');

            // Optional, because a rating on its own is a real review. Somebody
            // who gives four stars and no words has still said something.
            $table->text('body')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'product_id']);

            // How a listing's reviews are read: its own, newest first.
            $table->index(['product_id', 'id']);
        });

        DB::statement(
            'ALTER TABLE reviews ADD CONSTRAINT reviews_rating_range CHECK (rating BETWEEN 1 AND 5)'
        );

        // A body is absent or it says something. An empty string is neither, and
        // it would render as a review with nothing in it.
        DB::statement(
            'ALTER TABLE reviews ADD CONSTRAINT reviews_body_not_blank
             CHECK (body IS NULL OR length(btrim(body)) > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
