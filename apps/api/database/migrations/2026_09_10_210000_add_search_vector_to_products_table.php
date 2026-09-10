<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Full-text search, in PostgreSQL.
 *
 * **No search service.** Meilisearch or Typesense would give typo tolerance and
 * instant results, and would also give a second store to keep in step with this
 * one - a sync that is wrong for as long as nobody notices. PostgreSQL is
 * already here holding sessions, cache and the queue, and root `CLAUDE.md`
 * section 13 says a service arrives in the change that gives it a job to do.
 * This job does not need one.
 *
 * A **generated column** rather than a trigger or application code. The vector
 * is derived from `name` and `description` by the database, on write, always -
 * there is no code path that can forget to update it, because there is no code
 * path that updates it at all.
 *
 * Two details in the expression are load-bearing:
 *
 * `to_tsvector('english', ...)` names the configuration explicitly. The
 * one-argument form reads the session's `default_text_search_config`, which
 * makes it STABLE rather than IMMUTABLE, and PostgreSQL refuses to build a
 * generated column out of it. The error when you forget is not obvious.
 *
 * `setweight` puts the name in band A and the description in band B, so a
 * listing called "Olympus OM-1" outranks one that mentions an OM-1 in passing.
 * Without it every match scores the same and ranking means nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE products ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(name, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(description, '')), 'B')
            ) STORED
        SQL);

        // GIN rather than GiST: slower to build, much faster to search, and this
        // table is read far more than it is written.
        DB::statement('CREATE INDEX products_search_vector_index ON products USING GIN (search_vector)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS products_search_vector_index');

        Schema::table('products', function ($table): void {
            $table->dropColumn('search_vector');
        });
    }
};
