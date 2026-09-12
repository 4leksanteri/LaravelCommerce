<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Stripe\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakesStripe;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * Stripe telling this application that a payment moved (ADR 0040).
 *
 * **These events are the truth and the browser's report is a hint.** A buyer
 * whose browser closes half way through confirming still produces them, which
 * is why the row is brought into line from here rather than only from the
 * response to a confirmation.
 *
 * Sent the way Stripe sends one: a raw body and a signature, with no session
 * and no `Accept: application/json`.
 */
final class PaymentWebhookTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    public function test_a_succeeded_payment_is_brought_into_line_from_stripe(): void
    {
        $payment = Payment::factory()->create(['stripe_payment_intent_id' => 'pi_1Order']);
        $stripe = $this->fakeStripe()
            ->respond('GET', '/v1/payment_intents/pi_1Order', FakeStripe::paidIntent('pi_1Order'));

        // The event carries an older copy, as a late delivery does. What is
        // stored is the intent as Stripe has it now.
        $this->deliver('evt_1', 'payment_intent.succeeded', FakeStripe::paymentIntent('pi_1Order'))
            ->assertNoContent();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('pm_1Card', $payment->stripe_payment_method_id);
        $this->assertSame(1, $stripe->timesSentTo('GET', '/v1/payment_intents/pi_1Order'));
    }

    public function test_a_refused_payment_keeps_stripes_reason(): void
    {
        $payment = Payment::factory()->create(['stripe_payment_intent_id' => 'pi_1Order']);
        $this->fakeStripe()->respond(
            'GET',
            '/v1/payment_intents/pi_1Order',
            FakeStripe::refusedIntent('pi_1Order', 'Your card has insufficient funds.'),
        );

        $this->deliver('evt_1', 'payment_intent.payment_failed', FakeStripe::paymentIntent('pi_1Order'))
            ->assertNoContent();

        $payment->refresh();

        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('Your card has insufficient funds.', $payment->failure_reason);
        $this->assertNull($payment->paid_at);
    }

    public function test_the_same_event_delivered_twice_is_acted_on_once(): void
    {
        Payment::factory()->create(['stripe_payment_intent_id' => 'pi_1Order']);
        $stripe = $this->fakeStripe()
            ->respond('GET', '/v1/payment_intents/pi_1Order', FakeStripe::paidIntent('pi_1Order'));

        $this->deliver('evt_1', 'payment_intent.succeeded', FakeStripe::paymentIntent('pi_1Order'))->assertNoContent();
        $this->deliver('evt_1', 'payment_intent.succeeded', FakeStripe::paymentIntent('pi_1Order'))->assertNoContent();

        $this->assertSame(1, $stripe->timesSentTo('GET', '/v1/payment_intents/pi_1Order'));
        $this->assertDatabaseCount('stripe_events', 1);
    }

    /** The moment the money arrived is stamped once and not moved. */
    public function test_a_second_succeeded_event_does_not_move_the_moment_it_was_paid(): void
    {
        $payment = Payment::factory()->paid()->create(['stripe_payment_intent_id' => 'pi_1Order']);
        $paidAt = $payment->paid_at;

        $this->fakeStripe()->respond('GET', '/v1/payment_intents/pi_1Order', FakeStripe::paidIntent('pi_1Order'));

        $this->travel(2)->hours();
        $this->deliver('evt_2', 'payment_intent.succeeded', FakeStripe::paymentIntent('pi_1Order'))->assertNoContent();

        $afterwards = $payment->refresh()->paid_at;

        // A branch rather than an assertion, so the comparison below is reached
        // with two dates rather than with a promise of them.
        if (! $paidAt instanceof CarbonInterface || ! $afterwards instanceof CarbonInterface) {
            $this->fail('A paid payment should carry the moment it was paid.');
        }

        $this->assertTrue($paidAt->equalTo($afterwards));
    }

    /**
     * An intent this platform created for something that is not an order, or
     * one whose row was never written. There is nothing to bring into line,
     * and Stripe should not be told to retry forever.
     */
    public function test_an_intent_no_order_points_at_is_answered_and_ignored(): void
    {
        $stripe = $this->fakeStripe();

        $this->deliver('evt_1', 'payment_intent.succeeded', FakeStripe::paymentIntent('pi_Unknown'))
            ->assertNoContent();

        $stripe->assertNothingSent();
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * The event is recorded in the same transaction as its effect, so a
     * failure leaves nothing behind and Stripe's retry is acted on.
     */
    public function test_a_failure_leaves_no_record_so_stripe_can_try_again(): void
    {
        $payment = Payment::factory()->create(['stripe_payment_intent_id' => 'pi_1Order']);
        $this->fakeStripe()
            ->respond('GET', '/v1/payment_intents/pi_1Order', ['error' => ['type' => 'api_error', 'message' => 'Something went wrong.']], 500)
            ->respond('GET', '/v1/payment_intents/pi_1Order', FakeStripe::paidIntent('pi_1Order'));

        $this->deliver('evt_1', 'payment_intent.succeeded', FakeStripe::paymentIntent('pi_1Order'))->assertStatus(500);
        $this->assertDatabaseCount('stripe_events', 0);

        $this->deliver('evt_1', 'payment_intent.succeeded', FakeStripe::paymentIntent('pi_1Order'))->assertNoContent();
        $this->assertDatabaseCount('stripe_events', 1);
        $this->assertSame(PaymentStatus::Succeeded, $payment->refresh()->status);
    }

    /**
     * @param  array<string, mixed>  $object
     * @return TestResponse<Response>
     */
    private function deliver(string $id, string $type, array $object, string $secret = 'whsec_fake'): TestResponse
    {
        $payload = json_encode([
            'id' => $id,
            'object' => 'event',
            'api_version' => '2026-08-26.dahlia',
            'created' => time(),
            'livemode' => false,
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);

        return $this->call('POST', '/api/v1/webhooks/stripe', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => '*/*; q=0.5, application/xml',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, $secret),
        ], content: $payload);
    }
}
