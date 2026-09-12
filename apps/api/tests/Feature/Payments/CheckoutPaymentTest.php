<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\Currency;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesStripe;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * Paying for a basket with one card (ADR 0040).
 *
 * Stripe is `FakeStripe` throughout, so everything above the wire is the real
 * code and what is asserted on is the parameters that would leave for Stripe -
 * which is where the decisions in this change actually live: the amount comes
 * from the order, the first confirmation is on-session and the rest are not,
 * and each creation carries a key that makes a retry harmless.
 */
final class CheckoutPaymentTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = User::factory()->create();
    }

    /**
     * Two orders from one basket, in the same currency, as checkout writes
     * them.
     *
     * @return array{0: Order, 1: Order}
     */
    private function basket(string $checkoutReference = 'CHK1'): array
    {
        $first = Order::factory()->for($this->buyer)->create([
            'checkout_reference' => $checkoutReference,
            'currency' => Currency::EUR,
            'total_minor' => 2499,
        ]);

        $second = Order::factory()->for($this->buyer)->for(Seller::factory()->approved())->create([
            'checkout_reference' => $checkoutReference,
            'currency' => Currency::EUR,
            'total_minor' => 9500,
        ]);

        return [$first, $second];
    }

    private function withIntents(): FakeStripe
    {
        return $this->fakeStripe()
            ->respond('POST', '/v1/customers', FakeStripe::customer())
            ->respond('POST', '/v1/payment_intents', FakeStripe::paymentIntent('pi_1'))
            ->respond('POST', '/v1/payment_intents', FakeStripe::paymentIntent('pi_2'));
    }

    // --- Opening the intents -------------------------------------------------

    public function test_reading_a_checkout_opens_an_intent_for_every_order_in_it(): void
    {
        [$first, $second] = $this->basket();
        $stripe = $this->withIntents();

        $this->actingAs($this->buyer)
            ->getJson('/api/v1/checkouts/CHK1/payment')
            ->assertOk()
            ->assertJsonPath('data.is_paid', false)
            ->assertJsonCount(2, 'data.payments')
            ->assertJsonPath('data.payments.0.order_reference', $first->reference)
            ->assertJsonPath('data.payments.0.client_secret', 'pi_1_secret_fake');

        $this->assertSame(2, $stripe->timesSentTo('POST', '/v1/payment_intents'));
        $this->assertDatabaseCount('payments', 2);

        // The order's own total, not a figure from anywhere else.
        $this->assertSame(2499, Payment::query()->where('order_id', $first->id)->value('amount_minor'));
        $this->assertSame(9500, Payment::query()->where('order_id', $second->id)->value('amount_minor'));
    }

    /**
     * A duplicate intent is a second way to charge somebody, so the key that
     * prevents one is derived from the order rather than random.
     *
     * `headersSentTo` answers about the latest request to that endpoint, which
     * is the second order's intent - so that is the reference it carries.
     */
    public function test_creating_an_intent_carries_an_idempotency_key_of_the_orders_own(): void
    {
        [, $second] = $this->basket();
        $stripe = $this->withIntents();

        $this->actingAs($this->buyer)->getJson('/api/v1/checkouts/CHK1/payment')->assertOk();

        $headers = $stripe->headersSentTo('POST', '/v1/payment_intents');

        $keys = array_filter(
            $headers,
            static fn (string $header): bool => str_starts_with($header, 'Idempotency-Key: '),
        );

        $this->assertCount(1, $keys);
        $this->assertContains('Idempotency-Key: order-payment-'.$second->reference, $headers);
    }

    public function test_reading_it_again_does_not_open_a_second_intent(): void
    {
        $this->basket();
        $stripe = $this->withIntents();

        $this->actingAs($this->buyer)->getJson('/api/v1/checkouts/CHK1/payment')->assertOk();
        $this->actingAs($this->buyer)->getJson('/api/v1/checkouts/CHK1/payment')->assertOk();

        $this->assertSame(2, $stripe->timesSentTo('POST', '/v1/payment_intents'));
        $this->assertDatabaseCount('payments', 2);
    }

    /** A card cannot be kept without a customer, and one is enough. */
    public function test_the_buyer_gets_one_stripe_customer(): void
    {
        $this->basket();
        $stripe = $this->withIntents();

        $this->actingAs($this->buyer)->getJson('/api/v1/checkouts/CHK1/payment')->assertOk();

        $this->assertSame(1, $stripe->timesSentTo('POST', '/v1/customers'));
        $this->assertSame('cus_1Buyer', $this->buyer->refresh()->stripe_customer_id);

        $intent = $stripe->sentTo('POST', '/v1/payment_intents');
        $this->assertSame('cus_1Buyer', $intent['customer']);
        $this->assertSame('off_session', $intent['setup_future_usage']);
    }

    // --- Paying --------------------------------------------------------------

    public function test_one_card_pays_for_every_order_in_the_basket(): void
    {
        $this->basket();
        $stripe = $this->withIntents()
            ->respond('POST', '/v1/payment_intents/pi_1/confirm', FakeStripe::paidIntent('pi_1'))
            ->respond('POST', '/v1/payment_intents/pi_2/confirm', FakeStripe::paidIntent('pi_2'));

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkouts/CHK1/payment', ['payment_method' => 'pm_1Card'])
            ->assertOk()
            ->assertJsonPath('data.is_paid', true)
            // Nothing left to confirm, so nothing is handed out.
            ->assertJsonPath('data.payments.0.client_secret', null);

        $this->assertSame(
            [PaymentStatus::Succeeded, PaymentStatus::Succeeded],
            Payment::query()->orderBy('id')->get()->map->status->all(),
        );

        // The first is on-session: the buyer is at the keyboard. The rest are
        // charged against the card Stripe kept.
        $this->assertArrayNotHasKey('off_session', $stripe->sentTo('POST', '/v1/payment_intents/pi_1/confirm'));
        $this->assertSame('true', $stripe->sentTo('POST', '/v1/payment_intents/pi_2/confirm')['off_session']);
    }

    /**
     * Nothing can be charged against a card that has not been authenticated,
     * so the run stops and the browser is given what it needs to finish.
     */
    public function test_a_card_needing_authentication_stops_the_rest_of_the_basket(): void
    {
        $this->basket();
        $stripe = $this->withIntents()
            ->respond('POST', '/v1/payment_intents/pi_1/confirm', FakeStripe::paymentIntent('pi_1', 'requires_action'));

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkouts/CHK1/payment', ['payment_method' => 'pm_1Card'])
            ->assertOk()
            ->assertJsonPath('data.is_paid', false)
            ->assertJsonPath('data.payments.0.status', 'requires_action')
            ->assertJsonPath('data.payments.0.client_secret', 'pi_1_secret_fake');

        $this->assertSame(0, $stripe->timesSentTo('POST', '/v1/payment_intents/pi_2/confirm'));
    }

    /** A decline is an answer, not a failure of this application. */
    public function test_a_refused_card_is_recorded_in_stripes_own_words(): void
    {
        $this->basket();
        $this->withIntents()
            ->refuse('POST', '/v1/payment_intents/pi_1/confirm', 'payment_method', 'Your card was declined.')
            ->respond('GET', '/v1/payment_intents/pi_1', FakeStripe::refusedIntent('pi_1', 'Your card was declined.'));

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkouts/CHK1/payment', ['payment_method' => 'pm_1Card'])
            ->assertOk()
            ->assertJsonPath('data.is_paid', false)
            ->assertJsonPath('data.payments.0.status', 'failed')
            ->assertJsonPath('data.payments.0.failure_reason', 'Your card was declined.');
    }

    public function test_paying_a_checkout_that_is_already_paid_is_refused(): void
    {
        [$first, $second] = $this->basket();
        $this->fakeStripe();

        foreach ([$first, $second] as $order) {
            Payment::factory()->forOrder($order)->paid()->create();
        }

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkouts/CHK1/payment', ['payment_method' => 'pm_1Card'])
            ->assertConflict()
            ->assertJsonPath('message', 'This checkout has already been paid for.');
    }

    public function test_a_payment_method_this_did_not_come_from_stripe_is_refused(): void
    {
        $this->basket();
        $stripe = $this->fakeStripe();

        $this->actingAs($this->buyer)
            ->fromFrontend()
            ->postJson('/api/v1/checkouts/CHK1/payment', ['payment_method' => 'pi_1Order'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $stripe->assertNothingSent();
    }

    // --- Whose checkout it is ------------------------------------------------

    public function test_somebody_elses_checkout_is_not_found(): void
    {
        $this->basket();
        $stripe = $this->fakeStripe();

        $this->actingAs(User::factory()->create())
            ->getJson('/api/v1/checkouts/CHK1/payment')
            ->assertNotFound();

        $stripe->assertNothingSent();
    }

    public function test_a_checkout_that_never_existed_answers_the_same_way(): void
    {
        $this->fakeStripe();

        $this->actingAs($this->buyer)
            ->getJson('/api/v1/checkouts/NOSUCHREF/payment')
            ->assertNotFound();
    }

    public function test_paying_needs_a_signed_in_buyer(): void
    {
        $this->basket();

        $this->fromFrontend()
            ->postJson('/api/v1/checkouts/CHK1/payment', ['payment_method' => 'pm_1Card'])
            ->assertUnauthorized();
    }
}
