<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Currency;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A marketplace with something in it, for development.
 *
 * **Deliberately not called by DatabaseSeeder.** Categories are platform data
 * that every environment needs; five invented shops are not, and a `db:seed`
 * run anywhere that mattered must not quietly open them. This runs only when
 * somebody asks for it: `make seed-demo`.
 *
 * It exists because the storefront could not be checked without it. With one
 * unpublished listing in the database the home page rendered its empty state
 * and the product card had never drawn once - and the query-count test for
 * images found an N+1 precisely because several listings behave differently
 * from one.
 *
 * Chosen to exercise what the pages have to get right rather than to look busy:
 *
 *   - four currencies, because a card formats each one and none may be summed
 *   - listings with several sizes at different prices, so a card says "from"
 *   - two that are sold out, so a card says so and still shows a price
 *   - staggered publication dates, so "newest first" is a real ordering
 *
 * No photographs. Images go through the real upload pipeline (ADR 0016) and a
 * seeder that wrote files around it would test a path the application never
 * takes. A card with no photograph says so, which is what it should do anyway.
 *
 * Idempotent, keyed on each shop's slug: a shop that already exists is left
 * alone, so running this twice changes nothing.
 */
final class DemoCatalogueSeeder extends Seeder
{
    /** Every demo account signs in with this. Development only, never real. */
    public const string PASSWORD = 'demo-password-2026';

    /**
     * @var list<array{
     *     slug: string,
     *     name: string,
     *     currency: Currency,
     *     description: string,
     *     listings: list<array{name: string, category: string, description: string, variants: list<array{0: string, 1: int, 2: int}>}>
     * }>
     */
    private const array SHOPS = [
        [
            'slug' => 'northlight-analog',
            'name' => 'Northlight Analog',
            'currency' => Currency::EUR,
            'description' => 'Film cameras and lenses, each one tested with a roll before it is listed.',
            'listings' => [
                ['name' => 'Olympus OM-1 body, serviced', 'category' => 'film-cameras', 'description' => 'New light seals and a cleaned shutter. Meter reads correctly against a handheld.', 'variants' => [['Body only', 21900, 1]]],
                ['name' => 'Canon AE-1 Program with 50mm f/1.8', 'category' => 'film-cameras', 'description' => 'The squeak has been dealt with. Comes with the standard lens and a strap.', 'variants' => [['Chrome', 26900, 1], ['Black', 28900, 1]]],
                ['name' => 'Zuiko 50mm f/1.4 lens', 'category' => 'lenses', 'description' => 'Clean glass, no haze or fungus. Aperture blades snappy and dry.', 'variants' => [['Default', 12900, 2]]],
                ['name' => 'Paterson developing tank', 'category' => 'darkroom', 'description' => 'Two reels, both 35mm and 120. Light-tight.', 'variants' => [['Default', 2400, 0]]],
            ],
        ],
        [
            'slug' => 'retuned-audio',
            'name' => 'Retuned Audio',
            'currency' => Currency::SEK,
            'description' => 'Hi-fi from the seventies onwards, recapped and bench-tested in Malmo.',
            'listings' => [
                ['name' => 'Technics SL-1200 MK2 turntable', 'category' => 'turntables', 'description' => 'Pitch is stable, tonearm bearings are tight. New belt is not needed; it is direct drive.', 'variants' => [['Default', 849900, 1]]],
                ['name' => 'Sony WH-1000XM4 headphones, boxed', 'category' => 'headphones', 'description' => 'Barely used. Noise cancelling works as new, with the case and cable.', 'variants' => [['Black', 149900, 2], ['Silver', 159900, 1]]],
                ['name' => 'Marantz 2230 receiver, recapped', 'category' => 'amplifiers', 'description' => 'Every electrolytic replaced and the lamps converted to LED.', 'variants' => [['Default', 499900, 1]]],
            ],
        ],
        [
            'slug' => 'fret-and-valve',
            'name' => 'Fret & Valve',
            'currency' => Currency::GBP,
            'description' => 'Guitars and pedals, set up properly before they leave the bench.',
            'listings' => [
                ['name' => 'Fender Stratocaster, 1998 Mexican', 'category' => 'guitars', 'description' => 'Fresh frets, a proper setup and a new set of 10s.', 'variants' => [['Default', 42500, 1]]],
                ['name' => 'Boss DS-1 distortion pedal', 'category' => 'effects-pedals', 'description' => 'The orange one everybody starts with. Works, and has the scuffs to prove it.', 'variants' => [['Default', 3500, 4]]],
                ['name' => 'Electro-Harmonix Big Muff Pi', 'category' => 'effects-pedals', 'description' => 'NYC reissue. Every knob is scratch-free.', 'variants' => [['Default', 6500, 0]]],
            ],
        ],
        [
            'slug' => 'kallio-keys',
            'name' => 'Kallio Keys',
            'currency' => Currency::EUR,
            'description' => 'Synthesisers, vintage computers and the keyboards that go with them.',
            'listings' => [
                ['name' => 'Korg Minilogue, 4-voice', 'category' => 'synthesisers', 'description' => 'Analogue polysynth with the original power supply.', 'variants' => [['Default', 34900, 1]]],
                ['name' => 'Commodore 64C, tested', 'category' => 'vintage-computers', 'description' => 'Boots, loads from disk and outputs a clean picture over composite.', 'variants' => [['Default', 18900, 1]]],
                ['name' => 'IBM Model M keyboard, 1391401', 'category' => 'keyboards', 'description' => 'Buckling springs, cleaned inside and out. Comes with a PS/2 adapter.', 'variants' => [['Default', 12500, 2]]],
            ],
        ],
        [
            'slug' => 'second-hand-time',
            'name' => 'Second Hand Time',
            'currency' => Currency::DKK,
            'description' => 'Mechanical watches, regulated and pressure-tested in Aarhus.',
            'listings' => [
                ['name' => 'Omega Seamaster, 1970s', 'category' => 'wristwatches', 'description' => 'Serviced last year, with the paperwork.', 'variants' => [['Default', 1450000, 1]]],
                ['name' => 'Silver pocket watch, 1920s', 'category' => 'pocket-watches', 'description' => 'Hallmarked case, running and regulated.', 'variants' => [['Default', 220000, 1]]],
                // Last on purpose, so it is published late enough to reach the
                // home page's newest eight. It is the only multi-price listing
                // that does, which is what puts a "from" price on that page.
                ['name' => 'Seiko 5 automatic, SNK809', 'category' => 'wristwatches', 'description' => 'Keeps time within ten seconds a day. New canvas strap.', 'variants' => [['Canvas strap', 95000, 3], ['Steel bracelet', 115000, 1]]],
            ],
        ],
    ];

    public function run(): void
    {
        $this->call(CategorySeeder::class);

        $reviewer = $this->reviewer();
        $start = now()->subDays(20);
        $shopCount = count(self::SHOPS);

        foreach (self::SHOPS as $index => $shop) {
            if (Seller::query()->where('slug', $shop['slug'])->exists()) {
                continue;
            }

            $owner = User::factory()->create([
                'name' => $shop['name'].' owner',
                'email' => "demo-{$shop['slug']}@example.test",
                'password' => self::PASSWORD,
            ]);

            $seller = Seller::factory()->for($owner)->approved($reviewer)->create([
                'slug' => $shop['slug'],
                'shop_name' => $shop['name'],
                'currency' => $shop['currency'],
                'description' => $shop['description'],
                'contact_email' => "demo-{$shop['slug']}@example.test",
            ]);

            foreach ($shop['listings'] as $position => $listing) {
                // Round-robin across shops: every shop's first listing, then
                // every shop's second, and so on. Newest-first then reads as a
                // mix, which matters for more than looks - a first draft of this
                // advanced the clock shop by shop, and the home page's eight
                // cards came out as three shops' catalogues in a row, which is
                // the arrangement least likely to show a currency bug.
                $slot = $position * $shopCount + $index;

                $this->listing($seller, $listing, $start->copy()->addHours(6 * $slot));
            }
        }
    }

    /**
     * @param  array{name: string, category: string, description: string, variants: list<array{0: string, 1: int, 2: int}>}  $listing
     */
    private function listing(Seller $seller, array $listing, \DateTimeInterface $publishedAt): void
    {
        $category = Category::query()->where('slug', $listing['category'])->first();

        if (! $category instanceof Category) {
            throw new RuntimeException("CategorySeeder has no category '{$listing['category']}'.");
        }

        // The category is set before `published()` rather than overridden
        // after it, so the state sees one and never creates a stray category of
        // its own to satisfy the constraint.
        $product = Product::factory()
            ->for($seller, 'seller')
            ->state(['category_id' => $category->id])
            ->published()
            ->create([
                'name' => $listing['name'],
                'slug' => Str::slug($listing['name']),
                'description' => $listing['description'],
                'published_at' => $publishedAt,
            ]);

        foreach ($listing['variants'] as $position => [$name, $priceMinor, $stock]) {
            ProductVariant::factory()->for($product)->create([
                'name' => $name,
                'price_minor' => $priceMinor,
                'stock' => $stock,
                'position' => $position,
            ]);
        }
    }

    /** One reviewer for every demo shop, rather than a staff account per shop. */
    private function reviewer(): User
    {
        $existing = User::query()->where('email', 'demo-staff@example.test')->first();

        return $existing instanceof User
            ? $existing
            : User::factory()->staff()->create([
                'name' => 'Demo reviewer',
                'email' => 'demo-staff@example.test',
                'password' => self::PASSWORD,
            ]);
    }
}
