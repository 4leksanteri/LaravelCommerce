<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of thing a listing is.
 *
 * **Managed by platform staff, not by sellers.** A category set that every shop
 * can add to stops being a way to find anything - it becomes fifty spellings of
 * "bread". Sellers choose from the list; staff decide what is on it.
 *
 * Two levels, and no more. `parent_id` gives "Food > Bread", which is what a
 * navigation needs, and refusing a third avoids recursive queries and
 * unbounded breadcrumbs for a catalogue that has neither. Relaxing it later is
 * deleting a check; introducing it later would be a data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();

            $table->string('name');

            // The public address, derived from the name once and not rewritten
            // when the name changes - the same rule shops and products follow
            // (ADR 0007). Somebody has the link.
            //
            // Unique globally rather than per parent: a category URL is
            // /categories/bread, with no parent in it, so two "Bread" under
            // different parents would be the same address.
            $table->string('slug')->unique();

            /*
             * The parent, for the one level of nesting there is.
             *
             * restrictOnDelete rather than cascade. Deleting a parent that
             * still has children would silently take a whole branch of the
             * navigation with it; the action refuses first with an explanation,
             * and this is what holds if anything ever bypasses it.
             */
            $table->foreignId('parent_id')->nullable()
                ->constrained('categories')->restrictOnDelete();

            // Staff arrange the navigation. Alphabetical would put "Bread"
            // above "Cakes" and both above whatever matters most.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
