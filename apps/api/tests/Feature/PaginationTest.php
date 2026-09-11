<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Orders\PlacesOrders;
use Tests\TestCase;

/**
 * What a page looks like, asserted once for every endpoint that serves one.
 *
 * This exists because of a real defect rather than for tidiness. Laravel's
 * default envelope built absolute URLs from the forwarded host, so every
 * paginated response published `http://0.0.0.0:3000` - the Next.js server's
 * bind address inside its container - to the browser. Nothing asserted the
 * envelope at all, so nothing noticed.
 *
 * Written to cover **every** PaginatedCollection rather than one of them. A
 * sixth list endpoint that forgets the base class is the failure this is for,
 * and it is a failure a test against a single endpoint would not see.
 */
final class PaginationTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    private User $buyer;

    private User $shopOwner;

    private User $staff;

    private Seller $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
        $this->shopOwner = User::factory()->create();
        $this->staff = User::factory()->staff()->create();

        $this->shop = $this->approvedShop($this->shopOwner);
        $this->placeOrder($this->buyer, $this->publishedVariant($this->shop));
    }

    /**
     * Four numbers, no URLs. The client knows the path because it made the
     * request, and asks for the next page by putting `?page=` on the one it
     * used.
     */
    public function test_every_paginated_endpoint_carries_the_same_four_numbers(): void
    {
        foreach ($this->paginatedEndpoints() as $label => [$actor, $url]) {
            $body = $this->fetch($actor, $url)->assertOk()->json();

            $this->assertIsArray($body);
            $this->assertArrayHasKey('meta', $body, "{$label} has no meta");
            $this->assertArrayNotHasKey('links', $body, "{$label} still publishes pagination links");

            $this->assertSame(
                ['current_page', 'last_page', 'per_page', 'total'],
                array_keys($body['meta']),
                "{$label} does not carry the standard page",
            );
        }
    }

    /**
     * **The regression.** The API does not know the origin a browser reached it
     * on and must not guess: the browser calls relative paths on the Next
     * origin and the proxy forwards them (ADR 0003). An absolute URL in here is
     * either this application's own address or the proxy's internal one, and
     * neither is reachable from where the response is going.
     */
    public function test_a_paginated_response_never_leaks_an_origin(): void
    {
        foreach ($this->paginatedEndpoints() as $label => [$actor, $url]) {
            $content = $this->fetch($actor, $url)->assertOk()->content();

            $this->assertStringNotContainsString('http://', $content, "{$label} leaks an origin");
            $this->assertStringNotContainsString('https://', $content, "{$label} leaks an origin");
        }
    }

    /** The numbers are the paginator's, not a plausible-looking constant. */
    public function test_the_page_describes_the_set_it_came_from(): void
    {
        $this->assertSame(
            ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1],
            $this->fetch($this->buyer, '/api/v1/orders')->assertOk()->json('meta'),
        );
    }

    /**
     * **The published contract has to agree with the response.**
     *
     * `make api-check` proves the committed document is what the code
     * generates. It cannot prove the document is *true*, and that is the gap
     * this covers: one storefront listing paginated at runtime while Scramble,
     * unable to follow a scope reached through a relation, published it as a
     * plain array. Both halves were self-consistent and the frontend was still
     * told the wrong thing.
     *
     * This is the same failure as the `data: string[]` one that named
     * collections were introduced for, one level up the envelope.
     */
    public function test_the_contract_says_these_are_paginated(): void
    {
        $contract = json_decode((string) file_get_contents(base_path('openapi.json')), true);

        $this->assertIsArray($contract);

        foreach ($this->paginatedEndpoints() as $label => [, , $path]) {
            $operation = $contract['paths'][$path]['get'] ?? null;

            $this->assertIsArray($operation, "{$label} is missing from the contract as {$path}");

            $properties = $operation['responses'][200]['content']['application/json']['schema']['properties'] ?? null;

            $this->assertIsArray($properties, "{$label} publishes no JSON body");
            $this->assertArrayHasKey(
                'meta',
                $properties,
                "The contract publishes {$label} as an unpaginated set. Run `make api-docs`; "
                    .'if meta is still absent, Scramble could not see that the endpoint paginates.',
            );

            // The other half. Saying a set has twelve pages while documenting
            // no way to ask for the second one is half a contract, and `page`
            // is read straight off the request so nothing declares it for you.
            $this->assertContains(
                'page',
                array_column($operation['parameters'] ?? [], 'name'),
                "The contract never tells a client how to ask {$label} for another page",
            );
        }
    }

    /**
     * Walking off the end is empty, not an error.
     *
     * A client that asks for page 40 of 12 has usually just had something
     * deleted underneath it, and answering 404 would turn an ordinary race into
     * an error screen. `PaginatedCollection::PAGE_PARAMETER` promises this.
     */
    public function test_a_page_past_the_end_is_empty_rather_than_an_error(): void
    {
        $this->fetch($this->buyer, '/api/v1/orders?page=40')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 40)
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * Checkout answers with every order it made, so it is not a page and must
     * not claim to be one. A client that received half of a basket and had to
     * ask for the rest could not show a confirmation.
     */
    public function test_the_checkout_response_is_not_a_page(): void
    {
        $shop = $this->approvedShop(User::factory()->create());
        $buyer = User::factory()->create();

        $this->actingAs($buyer)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', ['variant_id' => $this->publishedVariant($shop)->id])
            ->assertOk();

        $response = $this->actingAs($buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkout', ['address_id' => $this->addressFor($buyer)->id])
            ->assertCreated();

        $this->assertSame(['data'], array_keys((array) $response->json()));
    }

    /**
     * Every audience, and every PaginatedCollection subclass.
     *
     * The third element is the endpoint's path in the OpenAPI document, which
     * is not always its URL.
     *
     * @return array<string, array{0: User|null, 1: string, 2: string}>
     */
    private function paginatedEndpoints(): array
    {
        return [
            'search' => [null, '/api/v1/search', '/search'],
            'a storefront' => [null, "/api/v1/shops/{$this->shop->slug}/products", '/shops/{shopSlug}/products'],
            'the order history' => [$this->buyer, '/api/v1/orders', '/orders'],
            'the shop order queue' => [$this->shopOwner, '/api/v1/seller/orders', '/seller/orders'],
            'the shop catalogue' => [$this->shopOwner, '/api/v1/seller/products', '/seller/products'],
            'the review queue' => [$this->staff, '/api/v1/admin/sellers', '/admin/sellers'],
        ];
    }

    /**
     * `actingAs` persists on the test instance, so a guest request made after
     * an authenticated one is not a guest request unless the guards are
     * forgotten first.
     *
     * @return TestResponse<Response>
     */
    private function fetch(?User $actor, string $url): TestResponse
    {
        Auth::forgetGuards();

        return $actor instanceof User
            ? $this->actingAs($actor)->getJson($url)
            : $this->getJson($url);
    }
}
