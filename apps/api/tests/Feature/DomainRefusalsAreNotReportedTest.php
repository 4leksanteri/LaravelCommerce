<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Feature\Orders\PlacesOrders;
use Tests\TestCase;

/**
 * A marketplace saying no is not a marketplace failing (ADR 0045).
 *
 * Somebody buys the last one, and the next person to press the button is told.
 * That is the shop working. Logged as an ERROR with a stack attached it is
 * indistinguishable from something broken, and it was the bulk of what this
 * application wrote.
 */
final class DomainRefusalsAreNotReportedTest extends TestCase
{
    use PlacesOrders;
    use RefreshDatabase;

    public function test_a_refusal_anybody_can_provoke_is_not_reported(): void
    {
        $buyer = User::factory()->create();
        $shop = $this->approvedShop(User::factory()->create());
        $variant = $this->publishedVariant($shop, stock: 1);

        $log = Log::spy();

        $this->actingAs($buyer)
            ->fromFrontend()
            ->postJson('/api/v1/cart/items', [
                'variant_id' => $variant->id,
                'quantity' => 5,
            ])
            ->assertStatus(409);

        // Asserted on the spy rather than through the facade. Both work at
        // runtime, and only this one is a method the analyser can find.
        $log->shouldNotHaveReceived('error');
        $log->shouldNotHaveReceived('log');
    }

    /**
     * The control, and this file is worth little without it.
     *
     * Every assertion above passes just as well when nothing reports anything
     * - a spy that is never consulted has received nothing either. This proves
     * the spy sees a report when one happens, so the silence above is the
     * marker working rather than the test looking in the wrong place.
     */
    public function test_something_genuinely_broken_is_still_reported(): void
    {
        $log = Log::spy();

        app(ExceptionHandler::class)->report(new RuntimeException('The database is on fire.'));

        $log->shouldHaveReceived('error');
    }
}
