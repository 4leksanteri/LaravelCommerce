<?php

declare(strict_types=1);

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Looking for something across the whole marketplace.
 *
 * PostgreSQL full-text search over a generated `tsvector` column, weighted so a
 * listing named for a thing outranks one that mentions it (ADR 0020).
 */
final class SearchTest extends TestCase
{
    use RefreshDatabase;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Seller::factory()->approved()->create(['slug' => 'northlight']);
    }

    public function test_it_finds_a_listing_by_name_without_an_account(): void
    {
        $this->list('Olympus OM-1 35mm SLR');
        $this->list('Nikon FE2');

        $this->assertGuest();

        $this->getJson('/api/v1/search?q=olympus')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Olympus OM-1 35mm SLR');
    }

    public function test_it_finds_a_listing_by_its_description(): void
    {
        $this->list('Nikon FE2', 'Comes with the original Zuiko lens cap.');

        $this->getJson('/api/v1/search?q=zuiko')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * The reason `setweight` is in the generated column. Without it every match
     * scores the same and "ranking" means whatever order the planner returns.
     */
    public function test_a_name_match_outranks_a_description_match(): void
    {
        $this->list('A camera bag', 'Fits an Olympus OM-1 comfortably.');
        $this->list('Olympus OM-1 body');

        $this->getJson('/api/v1/search?q=olympus')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Olympus OM-1 body');
    }

    /**
     * `websearch_to_tsquery` combines words with AND, so a search is a
     * conjunction rather than a shotgun.
     */
    public function test_every_word_has_to_match(): void
    {
        $this->list('Olympus OM-1 body');
        $this->list('Nikon 50mm lens');

        $this->getJson('/api/v1/search?q=olympus+lens')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/search?q=nikon+lens')->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * People type search syntax whether or not anybody supports it.
     * `websearch_to_tsquery` understands quoted phrases and a leading `-`, and
     * never throws on malformed input the way `to_tsquery` does.
     */
    public function test_it_understands_what_people_type_into_search_boxes(): void
    {
        $this->list('Olympus OM-1 body');
        $this->list('Olympus Trip 35');

        $this->getJson('/api/v1/search?q=olympus+-trip')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Olympus OM-1 body');

        $this->getJson('/api/v1/search?q='.urlencode('"Olympus Trip"'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Olympus Trip 35');
    }

    public function test_a_query_that_is_only_punctuation_does_not_fail(): void
    {
        $this->list('Olympus OM-1 body');

        $this->getJson('/api/v1/search?q='.urlencode('&&& ||'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** English stemming, which is why the configuration is named explicitly. */
    public function test_it_matches_across_singular_and_plural(): void
    {
        $this->list('Two camera bodies');

        $this->getJson('/api/v1/search?q=body')->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * **A known defect, pinned rather than described.**
     *
     * The Snowball English stemmer reads the trailing `s` of "lens" as a plural
     * and produces `len`; "lenses" produces `lens`. They do not meet, so
     * searching for a lens finds no lenses.
     *
     * On a marketplace that sells camera lenses this is not academic - it is
     * one of the likeliest queries there is. It is left alone for now because
     * every fix is a real decision: a synonym dictionary needs a file on the
     * database server, and appending `:*` to the last term would fix this while
     * making "cam" match "camera", which is a different product (ADR 0020).
     *
     * This test exists so that whoever does fix it finds a failure here and
     * changes it deliberately, rather than discovering the quirk again.
     */
    public function test_the_stemmer_does_not_connect_lens_to_lenses(): void
    {
        $this->list('Vintage camera lenses');

        $this->getJson('/api/v1/search?q=lens')->assertOk()->assertJsonCount(0, 'data');

        // The plural finds it, which is what makes this a stemmer quirk rather
        // than the index being broken.
        $this->getJson('/api/v1/search?q=lenses')->assertOk()->assertJsonCount(1, 'data');
    }

    // --- What is not visible --------------------------------------------------

    public function test_search_is_not_a_way_around_approval(): void
    {
        $pending = Seller::factory()->create();

        Product::factory()->for($pending, 'seller')->for(Category::factory())
            ->withVariant()->published()->create(['name' => 'Olympus OM-1 body']);

        $this->getJson('/api/v1/search?q=olympus')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_draft_is_not_searchable(): void
    {
        Product::factory()->for($this->shop, 'seller')->withVariant()
            ->create(['name' => 'Olympus OM-1 body']);

        $this->getJson('/api/v1/search?q=olympus')->assertOk()->assertJsonCount(0, 'data');
    }

    // --- Narrowing ------------------------------------------------------------

    public function test_it_can_be_narrowed_to_a_category_and_what_is_under_it(): void
    {
        $cameras = Category::factory()->create(['slug' => 'cameras']);
        $lenses = Category::factory()->under($cameras)->create(['slug' => 'lenses']);
        $audio = Category::factory()->create(['slug' => 'audio']);

        $this->list('Olympus OM-1 body', category: $cameras);
        $this->list('Olympus Zuiko 50mm', category: $lenses);
        $this->list('Olympus speaker', category: $audio);

        $this->getJson('/api/v1/search?q=olympus&category=cameras')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/search?q=olympus&category=audio')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * `q` is optional on purpose: this doubles as "everything, newest first",
     * which is the closest thing the marketplace has to a front page and is
     * what the search screen shows before anybody types.
     */
    public function test_no_query_lists_everything_newest_first(): void
    {
        $older = $this->list('Nikon FE2');
        $newer = $this->list('Olympus OM-1 body');

        $this->getJson('/api/v1/search')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.slug', $newer->slug)
            ->assertJsonPath('data.1.slug', $older->slug);
    }

    public function test_a_one_character_query_is_refused(): void
    {
        $this->getJson('/api/v1/search?q=o')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('q');
    }

    public function test_an_unknown_category_is_refused(): void
    {
        $this->getJson('/api/v1/search?category=no-such-thing')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category');
    }

    public function test_it_finds_nothing_gracefully(): void
    {
        $this->list('Olympus OM-1 body');

        $this->getJson('/api/v1/search?q=harpsichord')->assertOk()->assertJsonCount(0, 'data');
    }

    // --- The index ------------------------------------------------------------

    /**
     * **The failure mode this guards against is silent.** A query whose text
     * search configuration or expression differs from the one baked into the
     * generated column still runs and still returns the right rows - it just
     * stops using the index and scans the table instead. Nothing about the
     * response says so.
     *
     * Asking the planner is the only way to know.
     */
    public function test_the_search_uses_the_index_rather_than_scanning(): void
    {
        // A GIN index on three rows is not worth using, and the planner knows
        // it. Force the question so the plan reflects a real catalogue.
        DB::statement('SET enable_seqscan = off');

        try {
            $plan = DB::select(
                "EXPLAIN SELECT id FROM products WHERE search_vector @@ websearch_to_tsquery('english', ?)",
                ['olympus'],
            );

            // EXPLAIN's column is literally named "QUERY PLAN" - a space in it,
            // and not aliasable - so it is read as an array rather than as a
            // property nothing can type.
            $lines = [];

            foreach ($plan as $row) {
                foreach ((array) $row as $value) {
                    $lines[] = is_scalar($value) ? (string) $value : '';
                }
            }

            $text = implode("\n", $lines);

            $this->assertStringContainsString(
                'products_search_vector_index',
                $text,
                "The planner did not reach for the search index:\n{$text}",
            );
        } finally {
            DB::statement('SET enable_seqscan = on');
        }
    }

    private function list(string $name, ?string $description = null, ?Category $category = null): Product
    {
        return Product::factory()
            ->for($this->shop, 'seller')
            ->for($category ?? Category::factory())
            ->withVariant()
            ->published()
            ->create(['name' => $name, 'description' => $description]);
    }
}
