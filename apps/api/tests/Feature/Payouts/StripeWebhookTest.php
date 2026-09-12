<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Models\PayoutAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Stripe\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakesStripe;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * Stripe telling this application that something happened (ADR 0015,
 * ADR 0031).
 *
 * Every request here is sent the way Stripe sends one: a raw body and a
 * signature header, with no Origin, no Referer, no session, and not the
 * `Accept: application/json` that `postJson()` would add.
 */
final class StripeWebhookTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    /**
     * The event carries an older copy of the account that still wants the
     * seller's details. The account as Stripe has it now is verified, and that
     * is what gets stored: events can arrive out of order, and the current
     * account cannot.
     */
    public function test_a_signed_account_updated_refreshes_the_account_from_stripe_rather_than_from_the_event(): void
    {
        $account = PayoutAccount::factory()->create(['stripe_account_id' => 'acct_1Shop']);
        $stripe = $this->fakeStripe()->respond('GET', '/v1/accounts/acct_1Shop', FakeStripe::accountActive('acct_1Shop'));

        $this->deliver('evt_1', 'account.updated', FakeStripe::account('acct_1Shop'))->assertNoContent();

        $this->assertSame('active', $account->refresh()->status()->value);
        $this->assertSame(1, $stripe->timesSentTo('GET', '/v1/accounts/acct_1Shop'));
    }

    public function test_the_same_event_delivered_twice_is_acted_on_once(): void
    {
        PayoutAccount::factory()->create(['stripe_account_id' => 'acct_1Shop']);
        $stripe = $this->fakeStripe()->respond('GET', '/v1/accounts/acct_1Shop', FakeStripe::accountActive('acct_1Shop'));

        $this->deliver('evt_1', 'account.updated', FakeStripe::account('acct_1Shop'))->assertNoContent();
        $this->deliver('evt_1', 'account.updated', FakeStripe::account('acct_1Shop'))->assertNoContent();

        $this->assertSame(1, $stripe->timesSentTo('GET', '/v1/accounts/acct_1Shop'));
        $this->assertDatabaseCount('stripe_events', 1);
    }

    /**
     * The event is recorded in the same transaction as its effect, so a
     * failure leaves nothing behind and Stripe's retry is acted on.
     */
    public function test_a_failure_leaves_no_record_so_stripe_can_try_again(): void
    {
        $account = PayoutAccount::factory()->create(['stripe_account_id' => 'acct_1Shop']);
        $this->fakeStripe()
            ->respond('GET', '/v1/accounts/acct_1Shop', ['error' => ['type' => 'api_error', 'message' => 'Something went wrong.']], 500)
            ->respond('GET', '/v1/accounts/acct_1Shop', FakeStripe::accountActive('acct_1Shop'));

        $this->deliver('evt_1', 'account.updated', FakeStripe::account('acct_1Shop'))->assertStatus(500);
        $this->assertDatabaseCount('stripe_events', 0);

        $this->deliver('evt_1', 'account.updated', FakeStripe::account('acct_1Shop'))->assertNoContent();
        $this->assertDatabaseCount('stripe_events', 1);
        $this->assertSame('active', $account->refresh()->status()->value);
    }

    public function test_an_event_signed_with_another_secret_is_refused_and_nothing_happens(): void
    {
        $stripe = $this->fakeStripe();
        PayoutAccount::factory()->create(['stripe_account_id' => 'acct_1Shop']);

        $this->deliver('evt_1', 'account.updated', FakeStripe::account('acct_1Shop'), secret: 'whsec_somebody_else')
            ->assertStatus(400)
            ->assertJsonPath('message', 'The signature is missing or does not match.');

        $stripe->assertNothingSent();
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_an_unsigned_request_is_refused(): void
    {
        $stripe = $this->fakeStripe();

        $this->call('POST', '/api/v1/webhooks/stripe', server: ['CONTENT_TYPE' => 'application/json'], content: '{}')
            ->assertStatus(400);

        $stripe->assertNothingSent();
    }

    /**
     * The example here was `payment_intent.succeeded` until payments arrived,
     * and that is now one of the four this application does act on (ADR 0040).
     * A customer being created is not: this platform makes them itself and has
     * nothing to do when Stripe reports it.
     */
    public function test_events_this_application_does_not_act_on_are_acknowledged_and_forgotten(): void
    {
        $stripe = $this->fakeStripe();

        $this->deliver('evt_2', 'customer.created', ['id' => 'cus_1Buyer', 'object' => 'customer'])
            ->assertNoContent();

        $stripe->assertNothingSent();
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_an_account_no_shop_points_at_is_left_alone(): void
    {
        $stripe = $this->fakeStripe();

        $this->deliver('evt_3', 'account.updated', FakeStripe::account('acct_1Stranger'))->assertNoContent();

        $stripe->assertNothingSent();
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
            // What Stripe sends, which is not what postJson() would.
            'HTTP_ACCEPT' => '*/*; q=0.5, application/xml',
            'HTTP_STRIPE_SIGNATURE' => WebhookSignature::generateSignatureHeader($payload, $secret),
        ], content: $payload);
    }
}
