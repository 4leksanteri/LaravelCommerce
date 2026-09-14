<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Products\StoreProductImage;
use App\Actions\Reviews\LeaveReview;
use App\Enums\Currency;
use App\Enums\OrderActor;
use App\Enums\ProductStatus;
use App\Enums\SellerStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Report;
use App\Models\Review;
use App\Models\Seller;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
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
 *   - photographs on all but one listing, so both states are drawn
 *   - reviews on four listings and none on the rest, so both states are drawn
 *
 * **Photographs go through `StoreProductImage`, the code a seller's upload
 * reaches.** They are striped placeholders in the design export's own palette -
 * the export marks product photos the same way - and they are decoded,
 * stripped, resized and stored as WebP exactly as a real upload would be
 * (ADR 0016). A seeder that wrote image files straight to disk would exercise a
 * path the application never takes.
 *
 * Idempotent. A shop that already exists is left alone, a listing that already
 * has photographs gets no more, and a review somebody has already left is not
 * left twice - so running this on a database seeded before either existed adds
 * them, and running it twice changes nothing.
 */
final class DemoCatalogueSeeder extends Seeder
{
    /** Every demo account signs in with this. Development only, never real. */
    public const string PASSWORD = 'demo-password-2026';

    /**
     * The design export's own placeholder stripes, as two tones each. Pale on
     * purpose: they stand in for photographs, and nobody should mistake them
     * for one.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array STRIPES = [
        ['#e8ecf3', '#f1f4f9'],
        ['#ece7dd', '#f4f0e8'],
        ['#e3ecea', '#eef4f2'],
        ['#ebe6ee', '#f3f0f5'],
    ];

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
                ['name' => 'Seiko 5 automatic, SNK809', 'category' => 'wristwatches', 'description' => 'Keeps time within ten seconds a day. New canvas strap.', 'variants' => [['Canvas strap', 95000, 24], ['Steel bracelet', 115000, 1]]],
            ],
        ],
    ];

    /**
     * Who the seeded reviews are by.
     *
     * Invented buyers rather than the demo shopper, and that is load-bearing:
     * there is one review per buyer per listing, and the end-to-end suite leaves
     * the shopper's own on the Seiko and rewrites it on every run after. A
     * seeded review under that account would take the only one it is allowed and
     * leave the suite with nothing to write.
     *
     * @var array<string, string>
     */
    private const array BUYERS = [
        'aino' => 'Aino Virtanen',
        'mikael' => 'Mikael Lindqvist',
        'sofia' => 'Sofia Berg',
        'jonas' => 'Jonas Halvorsen',
    ];

    /**
     * What people said, and how long ago.
     *
     * Four listings across four shops, which leaves most of the catalogue
     * unreviewed on purpose - a listing nobody has bought yet is the ordinary
     * case and has to look right too. The Seiko is deliberately not among them,
     * because it is the listing the end-to-end suite buys and reviews.
     *
     * Not every review is five marks and not every one has words: a rating on
     * its own is a review, and a wall of uniform praise is the least useful
     * thing to build a page against.
     *
     * @var list<array{
     *     shop: string,
     *     listing: string,
     *     said: list<array{buyer: string, rating: int, days: int, body: string|null}>
     * }>
     */
    private const array REVIEWS = [
        [
            'shop' => 'northlight-analog',
            'listing' => 'Olympus OM-1 body, serviced',
            'said' => [
                ['buyer' => 'aino', 'rating' => 5, 'days' => 18, 'body' => 'Shutter sounds right at every speed and the meter agreed with my handheld to within a third of a stop. Packed properly, too.'],
                ['buyer' => 'mikael', 'rating' => 4, 'days' => 12, 'body' => 'As described, and the new seals are obvious. One mark off for the strap lugs, which are more worn than the photographs suggest.'],
                ['buyer' => 'sofia', 'rating' => 5, 'days' => 4, 'body' => 'Second body I have bought from this shop and it arrived in the same state as the first.'],
            ],
        ],
        [
            'shop' => 'retuned-audio',
            'listing' => 'Technics SL-1200 MK2 turntable',
            'said' => [
                ['buyer' => 'jonas', 'rating' => 5, 'days' => 15, 'body' => 'Held pitch against a strobe for an hour without drifting. Bearings are tight and it was crated like something that costs this much.'],
                ['buyer' => 'aino', 'rating' => 4, 'days' => 6, 'body' => 'Works perfectly. The lid has a scratch that was mentioned in the description and is easier to see in person than in the photographs.'],
            ],
        ],
        [
            'shop' => 'fret-and-valve',
            'listing' => 'Boss DS-1 distortion pedal',
            // A rating and nothing else, which the listing has to draw as
            // readily as a paragraph.
            'said' => [
                ['buyer' => 'mikael', 'rating' => 3, 'days' => 8, 'body' => null],
            ],
        ],
        [
            'shop' => 'kallio-keys',
            'listing' => 'Korg Minilogue, 4-voice',
            'said' => [
                ['buyer' => 'sofia', 'rating' => 5, 'days' => 2, 'body' => 'In tune out of the box and the original supply was included, which is half the reason I bought this one rather than a cheaper listing.'],
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

        $this->photograph();
        $this->shopper();
        $this->applicant();
        $this->hopefuls();
        $this->settingsTester();
        $this->restock();
        $this->reopen();
        $this->reinstate();
        $this->reviews();
    }

    /**
     * An account whose password and address the suite changes, given both back
     * on every run.
     *
     * Changing a password signs out every other session the account has
     * (ADR 0034), so the suite cannot do it to the shopper or the shop owner
     * whose saved sessions every other test depends on. This account is used by
     * one test and nothing else.
     */
    private function settingsTester(): void
    {
        $tester = User::query()->where('email', 'demo-settings@example.test')->first() ?? new User;

        $tester->forceFill([
            'name' => 'Demo settings tester',
            'email' => 'demo-settings@example.test',
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
        ])->save();
    }

    /**
     * Somebody to apply to sell with, put back to having no shop on every run.
     *
     * The end-to-end suite applies with this account, and an application
     * cannot be withdrawn: without the reset, the second run would find a shop
     * already awaiting review and have nothing to apply for. Development data
     * only, like restock().
     *
     * A pending shop has nothing hanging off it that matters. It cannot publish,
     * so it has no orders, and anything it drafted goes with it. If somebody
     * approves it by hand and it takes an order, the delete below fails loudly
     * on the orders' foreign key rather than taking a receipt with it.
     */
    private function applicant(): void
    {
        $applicant = User::query()->where('email', 'demo-applicant@example.test')->first()
            ?? User::factory()->create([
                'name' => 'Demo applicant',
                'email' => 'demo-applicant@example.test',
                'password' => self::PASSWORD,
            ]);

        $applicant->seller()->delete();
    }

    /**
     * Two shops awaiting review, put back on every run.
     *
     * The end-to-end suite approves one and turns the other down, and a
     * decision cannot be undone (ADR 0007): without the reset, the second run
     * would open the review queue and find nothing to decide. They also give
     * the queue something to show anybody looking at the demo data.
     *
     * Development data only, like applicant(). A pending shop cannot publish,
     * so it has no orders and nothing hangs off it.
     */
    private function hopefuls(): void
    {
        $hopefuls = [
            [
                'email' => 'demo-hopeful@example.test',
                'name' => 'Demo hopeful',
                'slug' => 'bench-and-bellows',
                'shop_name' => 'Bench and Bellows',
                'currency' => Currency::EUR,
                'description' => 'Accordions and squeezeboxes, each one repaired and played through before it is listed.',
                'applied_at' => now()->subDays(2),
            ],
            [
                'email' => 'demo-hopeful-two@example.test',
                'name' => 'Demo second hopeful',
                'slug' => 'copper-kettle-audio',
                'shop_name' => 'Copper Kettle Audio',
                'currency' => Currency::SEK,
                'description' => 'Valve amplifiers, rebuilt from the chassis up.',
                'applied_at' => now()->subDay(),
            ],
        ];

        foreach ($hopefuls as $hopeful) {
            $applicant = User::query()->where('email', $hopeful['email'])->first()
                ?? User::factory()->create([
                    'name' => $hopeful['name'],
                    'email' => $hopeful['email'],
                    'password' => self::PASSWORD,
                ]);

            $applicant->seller()->delete();

            Seller::factory()->for($applicant)->create([
                'slug' => $hopeful['slug'],
                'shop_name' => $hopeful['shop_name'],
                'currency' => $hopeful['currency'],
                'description' => $hopeful['description'],
                'contact_email' => $hopeful['email'],
                'applied_at' => $hopeful['applied_at'],
            ]);
        }
    }

    /**
     * Every run puts demo stock back to what the listings above say.
     *
     * The end-to-end suite places real orders, and checkout takes stock
     * (ADR 0011). It cancels what it placed, which gives the stock back through
     * the buyer's own cancellation - but a run that fails half way through would
     * leave the catalogue a little more sold out each time, until the listings
     * the suite depends on were gone. This is the net under that.
     *
     * Development data only. An order already placed is a snapshot and is not
     * touched by this.
     */
    private function restock(): void
    {
        foreach (self::SHOPS as $shop) {
            $seller = Seller::query()->where('slug', $shop['slug'])->first();

            if (! $seller instanceof Seller) {
                continue;
            }

            foreach ($shop['listings'] as $listing) {
                $product = $seller->products()->where('slug', Str::slug($listing['name']))->first();

                if (! $product instanceof Product) {
                    continue;
                }

                foreach ($listing['variants'] as [$name, , $stock]) {
                    $product->variants()->where('name', $name)->update(['stock' => $stock]);
                }
            }
        }
    }

    /**
     * A few listings with something said about them.
     *
     * **Each review is earned the way a real one is.** A review needs a
     * completed order behind it (ADR 0047), so this builds one and then writes
     * the review through `LeaveReview` - the action the endpoint calls,
     * entitlement check and all. Inserting a row into `reviews` instead would
     * put demo data in the database that the application itself has no way to
     * produce, which is the same argument photograph() makes for going through
     * `StoreProductImage`.
     *
     * The orders are built with factories rather than through checkout, and that
     * is what keeps this offline: `PlaceOrders` would take stock that restock()
     * has just put back, and paying for one would call Stripe on every run of
     * `make seed-demo` - which is every run of `make e2e`.
     *
     * Idempotent by the review rather than by the order: a buyer who has already
     * said their piece about a listing is skipped, and so is the order that
     * would have entitled them to say it again.
     */
    private function reviews(): void
    {
        $leave = app(LeaveReview::class);

        foreach (self::REVIEWS as $reviewed) {
            $seller = Seller::query()->where('slug', $reviewed['shop'])->first();

            if (! $seller instanceof Seller) {
                continue;
            }

            $product = $seller->products()->where('slug', Str::slug($reviewed['listing']))->first();

            if (! $product instanceof Product) {
                continue;
            }

            $variant = $product->variants()->orderBy('position')->first();

            if (! $variant instanceof ProductVariant) {
                continue;
            }

            foreach ($reviewed['said'] as $said) {
                $buyer = $this->buyer($said['buyer']);

                if (Review::query()->where('user_id', $buyer->id)->where('product_id', $product->id)->exists()) {
                    continue;
                }

                $written = now()->subDays($said['days']);

                $this->received($buyer, $seller, $product, $variant, $written);

                $review = $leave->handle($buyer, $product, $said['rating'], $said['body']);

                /*
                 * Both dates together. `wasEdited()` is `updated_at` being later
                 * than `created_at`, so moving only the first would mark every
                 * seeded review as one that had been rewritten.
                 */
                $review->forceFill(['created_at' => $written, 'updated_at' => $written])->save();
            }
        }
    }

    /**
     * The completed order one review is earned by: accepted, sent, and
     * confirmed as arrived the day before the review was written.
     *
     * **Its payment is paid and never transferred**, which is not an omission -
     * it is what this marketplace does with these shops. Completing an order
     * releases the money through `TransferToShop`, and that returns early for a
     * shop with no active payout account, which is every demo shop.
     */
    private function received(
        User $buyer,
        Seller $seller,
        Product $product,
        ProductVariant $variant,
        CarbonInterface $written,
    ): void {
        $completed = $written->copy()->subDay();
        $shipped = $completed->copy()->subDays(4);
        $accepted = $shipped->copy()->subDay();

        $order = Order::factory()
            ->for($buyer, 'user')
            ->for($seller, 'seller')
            ->completed()
            ->paid()
            ->create([
                // The shop's currency, and a total the one line adds up to. The
                // payment copies both from here, so an order left at the
                // factory's zero would be a receipt for nothing.
                'currency' => $seller->currency,
                'total_minor' => $variant->price_minor,
                'created_at' => $accepted->copy()->subDay(),
                'accepted_at' => $accepted,
                'shipped_at' => $shipped,
                'auto_complete_at' => $shipped->copy()->addDays((int) config('orders.auto_complete_after_days')),
                'completed_at' => $completed,
                'completed_by' => OrderActor::Buyer,
            ]);

        OrderItem::factory()->for($order)->create([
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'variant_name' => $variant->name,
            'unit_price_minor' => $variant->price_minor,
            'quantity' => 1,
        ]);
    }

    /** One of the invented buyers above, made once and found thereafter. */
    private function buyer(string $key): User
    {
        $name = self::BUYERS[$key] ?? throw new RuntimeException("There is no demo buyer called '{$key}'.");
        $email = "demo-buyer-{$key}@example.test";

        $existing = User::query()->where('email', $email)->first();

        return $existing instanceof User
            ? $existing
            : User::factory()->create([
                'name' => $name,
                'email' => $email,
                'password' => self::PASSWORD,
            ]);
    }

    /**
     * Every run puts the demo shops back to trading.
     *
     * A shop can be suspended now (ADR 0052), and nothing else puts one back:
     * `restock()` is this net under stock, and `applicant()` and `hopefuls()`
     * are the same net under decisions that cannot be undone.
     *
     * Without it, an end-to-end run that suspended a shop and failed before
     * reinstating it would leave it suspended for every run afterwards - and a
     * suspended shop is **invisible**, so the damage would surface as a listing
     * that is suddenly not found rather than as a shop that has been stopped.
     * That is the same shape as the migration trap in root `CLAUDE.md`
     * section 14, and it is worth the same kind of net.
     *
     * Development data only. The hopefuls are deliberately left pending and are
     * not in SHOPS, so this does not reach them.
     */
    private function reopen(): void
    {
        foreach (self::SHOPS as $shop) {
            $seller = Seller::query()->where('slug', $shop['slug'])->first();

            if (! $seller instanceof Seller || $seller->status === SellerStatus::Approved) {
                continue;
            }

            $seller->forceFill([
                'status' => SellerStatus::Approved,
                'suspended_at' => null,
                'suspension_reason' => null,
                'suspended_by' => null,
            ])->save();
        }
    }

    /**
     * Every run puts back what moderation took down (ADR 0054).
     *
     * The third net of this kind and the one most needed, because a takedown is
     * deliberately **sticky**: `PublishProduct` refuses a removed listing and
     * `products_removed_is_not_published` enforces it, so unlike stock and
     * unlike a suspension there is no endpoint anywhere that undoes one. An
     * end-to-end run that upheld a report and stopped would take a demo listing
     * off the marketplace for good - and `listing()` above only builds listings
     * for a shop that does not exist yet, so re-seeding would not bring it back
     * either.
     *
     * It would surface exactly as `reopen()` warns: a listing that is suddenly
     * not found rather than one that was taken down.
     *
     * **The original publication date cannot be recovered**, because a takedown
     * clears it - so a restored listing is put back at the same instant the
     * catalogue starts from. That sorts it oldest, which disturbs least: dating
     * it now would push it into the home page's newest eight, where it was
     * never meant to be.
     *
     * The open reports go too. Nothing seeds one, so every report in a
     * development database is a leftover from a run that did not get to decide
     * it, and keeping them would grow the queue by one on every run.
     *
     * Development data only, like restock() and reopen().
     */
    private function reinstate(): void
    {
        Report::query()->delete();

        Review::query()->whereNotNull('hidden_at')->update([
            'hidden_at' => null,
            'hidden_reason' => null,
            'hidden_by' => null,
        ]);

        foreach (self::SHOPS as $shop) {
            $seller = Seller::query()->where('slug', $shop['slug'])->first();

            if (! $seller instanceof Seller) {
                continue;
            }

            foreach ($seller->products()->whereNotNull('removed_at')->get() as $product) {
                /*
                 * All five columns in one write. `products_removal_is_whole`
                 * ties the three removal columns to one another, and
                 * `products_removed_is_not_published` refuses a removed listing
                 * that is on sale - so clearing the removal and putting the
                 * listing back cannot be two saves without passing through a
                 * state the database rejects.
                 */
                $product->forceFill([
                    'removed_at' => null,
                    'removal_reason' => null,
                    'removed_by' => null,
                    'status' => ProductStatus::Published,
                    'published_at' => now()->subDays(20),
                ])->save();
            }
        }
    }

    /**
     * Somebody to buy with. `make e2e` signs in as this account once per run,
     * and anybody trying the storefront by hand can too.
     *
     * One account for the suite rather than one per test, because registration
     * is limited to ten an hour per IP and signing in to five a minute per
     * address (AppServiceProvider). Those are the production limits, and the
     * end-to-end suite works within them rather than being given looser ones
     * of its own.
     */
    private function shopper(): void
    {
        if (User::query()->where('email', 'demo-shopper@example.test')->exists()) {
            return;
        }

        User::factory()->create([
            'name' => 'Demo shopper',
            'email' => 'demo-shopper@example.test',
            'password' => self::PASSWORD,
        ]);
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

    /**
     * Three photographs on each shop's first listing, two on its second, one on
     * its third, none on a fourth - so a gallery with several, a card with one
     * and a card with none are all somewhere in the catalogue.
     *
     * Only listings that have none yet, which is what makes this safe to run on
     * a database seeded before it existed.
     */
    private function photograph(): void
    {
        $store = app(StoreProductImage::class);

        foreach (self::SHOPS as $index => $shop) {
            $seller = Seller::query()->where('slug', $shop['slug'])->first();

            if (! $seller instanceof Seller) {
                continue;
            }

            foreach ($shop['listings'] as $position => $listing) {
                $product = $seller->products()->where('slug', Str::slug($listing['name']))->first();

                if (! $product instanceof Product || $product->images()->exists()) {
                    continue;
                }

                for ($number = 0; $number < 3 - $position; $number++) {
                    $path = $this->placeholder($index + $number, landscape: $number % 2 === 0);

                    try {
                        $store->handle(
                            $product,
                            new UploadedFile($path, "{$product->slug}-{$number}.jpg", 'image/jpeg', null, true),
                        );
                    } finally {
                        if (is_file($path)) {
                            unlink($path);
                        }
                    }
                }
            }
        }
    }

    /**
     * A JPEG of diagonal stripes, written to a temporary file.
     *
     * Landscape and square alternate, so a gallery shows both proportions and a
     * square card has to crop one of them.
     */
    private function placeholder(int $seed, bool $landscape): string
    {
        [$width, $height] = $landscape ? [1600, 1200] : [1200, 1200];
        [$ground, $stripe] = self::STRIPES[$seed % count(self::STRIPES)];

        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('GD could not allocate a placeholder photograph.');
        }

        imagefill($image, 0, 0, $this->colour($image, $ground));
        imagesetthickness($image, 36);

        $ink = $this->colour($image, $stripe);

        for ($x = -$height; $x < $width; $x += 72) {
            imageline($image, $x, $height, $x + $height, 0, $ink);
        }

        $path = tempnam(sys_get_temp_dir(), 'demo-photograph-');

        if ($path === false || ! imagejpeg($image, $path, 85)) {
            throw new RuntimeException('The placeholder photograph could not be written.');
        }

        return $path;
    }

    private function colour(\GdImage $image, string $hex): int
    {
        $colour = imagecolorallocate(
            $image,
            $this->channel($hex, 1),
            $this->channel($hex, 3),
            $this->channel($hex, 5),
        );

        if ($colour === false) {
            throw new RuntimeException("GD could not allocate the colour {$hex}.");
        }

        return $colour;
    }

    /**
     * One channel of a `#rrggbb` colour.
     *
     * Two hex digits are always 0-255, but nothing about `hexdec` says so, and
     * GD is typed to refuse anything outside that range. The bounds are applied
     * rather than asserted, so the declared range is one PHPStan can prove from
     * the code instead of one it has to take on trust (apps/api CLAUDE.md
     * section 11).
     *
     * @return int<0, 255>
     */
    private function channel(string $hex, int $offset): int
    {
        return max(0, min(255, (int) hexdec(substr($hex, $offset, 2))));
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
