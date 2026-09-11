<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Enums\Currency;
use App\Models\PayoutAccount;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Stripe\Exception\InvalidRequestException;
use Tests\Support\FakesStripe;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * Opening a shop's payout account at Stripe, and filling in what Stripe asks
 * for (ADR 0031).
 *
 * Stripe is faked at the network, so what these assert about is what would
 * actually leave for Stripe. FakeStripe says why that seam.
 */
final class PayoutAccountTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private const string ENDPOINT = '/api/v1/seller/payout-account';

    /**
     * Reading is the stored copy of Stripe's answer and never calls Stripe -
     * which is also why this passes with no Stripe key configured at all.
     */
    public function test_a_shop_without_an_account_has_not_started_and_may_start(): void
    {
        $seller = Seller::factory()->approved()->create();

        $this->actingAs($seller->user)
            ->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.status', 'not_started')
            ->assertJsonPath('data.can_open', true)
            ->assertJsonPath('data.country', null)
            ->assertJsonPath('data.due', [])
            ->assertJsonPath('data.unsupported', [])
            ->assertJsonPath('data.bank_account_last4', null);
    }

    public function test_where_an_account_may_be_opened_is_sent_with_the_answer(): void
    {
        $seller = Seller::factory()->approved()->create();

        $countries = $this->actingAs($seller->user)->getJson(self::ENDPOINT)->json('data.countries');

        $this->assertContains('FI', $countries);
        $this->assertContains('GB', $countries);
        // A shop may price in dollars; a seller based in the US is not
        // somebody this platform can pay yet (PayoutCountry).
        $this->assertNotContains('US', $countries);
    }

    public function test_somebody_without_a_shop_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    public function test_opening_asks_stripe_for_an_account_whose_verification_this_platform_collects(): void
    {
        $stripe = $this->fakeStripe()->respond('POST', '/v1/accounts', FakeStripe::account('acct_1Opened'));
        $seller = Seller::factory()->approved()->create();

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'Firefox')
            ->postJson(self::ENDPOINT, ['country' => 'FI', 'accept_terms' => true])
            ->assertCreated()
            ->assertJsonPath('data.status', 'action_required')
            ->assertJsonPath('data.country', 'FI')
            ->assertJsonPath('data.can_open', false);

        $sent = $stripe->sentTo('POST', '/v1/accounts');

        $this->assertSame('FI', $sent['country']);
        $this->assertSame('individual', $sent['business_type']);
        $this->assertSame([
            'fees' => ['payer' => 'application'],
            'losses' => ['payments' => 'application'],
            'requirement_collection' => 'application',
            'stripe_dashboard' => ['type' => 'none'],
        ], $sent['controller']);
        // Transfers and nothing else: the platform charges, and the shop is
        // paid by transfer when an order completes.
        $this->assertSame(['transfers' => ['requested' => 'true']], $sent['capabilities']);
        $this->assertSame('5931', $sent['business_profile']['mcc']);
        $this->assertSame('203.0.113.9', $sent['tos_acceptance']['ip']);
        $this->assertSame('Firefox', $sent['tos_acceptance']['user_agent']);
        $this->assertSame((string) $seller->id, $sent['metadata']['seller_id']);

        $this->assertDatabaseHas('payout_accounts', [
            'seller_id' => $seller->id,
            'stripe_account_id' => 'acct_1Opened',
            'country' => 'FI',
            'terms_accepted_ip' => '203.0.113.9',
            'terms_accepted_user_agent' => 'Firefox',
        ]);
    }

    /**
     * The one call that creates something carries a key, so a retry after a
     * network failure is the same request rather than a second account.
     */
    public function test_opening_is_sent_with_an_idempotency_key(): void
    {
        $stripe = $this->fakeStripe()->respond('POST', '/v1/accounts', FakeStripe::account());
        $seller = Seller::factory()->approved()->create();

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->postJson(self::ENDPOINT, ['country' => 'FI', 'accept_terms' => true])
            ->assertCreated();

        $keys = array_filter(
            $stripe->headersSentTo('POST', '/v1/accounts'),
            static fn (string $header): bool => str_starts_with($header, 'Idempotency-Key: '),
        );

        $this->assertCount(1, $keys);
    }

    public function test_what_stripe_asks_for_is_said_in_this_apis_fields_and_the_rest_is_named(): void
    {
        $this->fakeStripe()->respond('POST', '/v1/accounts', FakeStripe::account(requirements: [
            'currently_due' => [
                'individual.dob.day',
                'individual.dob.month',
                'individual.dob.year',
                'external_account',
                'individual.political_exposure',
            ],
            'past_due' => ['individual.address.line1'],
        ]));
        $seller = Seller::factory()->approved()->create();

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->postJson(self::ENDPOINT, ['country' => 'FI', 'accept_terms' => true])
            ->assertCreated()
            // Three of Stripe's paths are one date of birth, and the order is
            // the form's rather than Stripe's.
            ->assertJsonPath('data.due', ['date_of_birth', 'address', 'iban'])
            ->assertJsonPath('data.unsupported', ['individual.political_exposure']);
    }

    public function test_opening_needs_stripes_terms_accepted(): void
    {
        $stripe = $this->fakeStripe();
        $seller = Seller::factory()->approved()->create();

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->postJson(self::ENDPOINT, ['country' => 'FI'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accept_terms');

        $stripe->assertNothingSent();
    }

    public function test_a_country_this_platform_cannot_pay_into_is_refused_before_stripe_is_asked(): void
    {
        $stripe = $this->fakeStripe();
        $seller = Seller::factory()->approved()->create();

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->postJson(self::ENDPOINT, ['country' => 'US', 'accept_terms' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country');

        $stripe->assertNothingSent();
    }

    public function test_stripe_refusing_the_country_is_put_beside_the_country(): void
    {
        $this->fakeStripe()->refuse('POST', '/v1/accounts', 'country', 'Accounts cannot be opened in that country.');
        $seller = Seller::factory()->approved()->create();

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->postJson(self::ENDPOINT, ['country' => 'MT', 'accept_terms' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['country' => 'Accounts cannot be opened in that country.']);

        $this->assertDatabaseCount('payout_accounts', 0);
    }

    /**
     * Verification details for a shop nobody has reviewed would be a person's
     * identity collected for a shop that may never trade.
     */
    public function test_a_shop_awaiting_review_cannot_open_one(): void
    {
        $stripe = $this->fakeStripe();
        $seller = Seller::factory()->create();

        $this->actingAs($seller->user)
            ->getJson(self::ENDPOINT)
            ->assertJsonPath('data.can_open', false);

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->postJson(self::ENDPOINT, ['country' => 'FI', 'accept_terms' => true])
            ->assertConflict()
            ->assertJsonPath('message', 'This shop has not been approved yet. A payout account can be opened once it has.');

        $stripe->assertNothingSent();
    }

    public function test_a_shop_cannot_open_a_second_account(): void
    {
        $stripe = $this->fakeStripe();
        $account = PayoutAccount::factory()->create();

        $this->actingAs($account->seller->user)
            ->fromFrontend()
            ->postJson(self::ENDPOINT, ['country' => 'FI', 'accept_terms' => true])
            ->assertConflict();

        $stripe->assertNothingSent();
        $this->assertDatabaseCount('payout_accounts', 1);
    }

    /**
     * `can_open` and OpenPayoutAccount are two readings of one rule. If either
     * changes without the other, this fails.
     */
    public function test_can_open_agrees_with_what_opening_does(): void
    {
        $this->fakeStripe()->respond('POST', '/v1/accounts', FakeStripe::account());

        $shops = [
            'awaiting review' => Seller::factory()->create(),
            'rejected' => Seller::factory()->rejected()->create(),
            'approved' => Seller::factory()->approved()->create(),
            'already open' => PayoutAccount::factory()->create()->seller,
        ];

        foreach ($shops as $label => $seller) {
            $canOpen = $this->actingAs($seller->user)->getJson(self::ENDPOINT)->json('data.can_open');

            $opened = $this->actingAs($seller->user)
                ->fromFrontend()
                ->postJson(self::ENDPOINT, ['country' => 'FI', 'accept_terms' => true])
                ->status();

            $this->assertSame($canOpen ? 201 : 409, $opened, $label);
        }
    }

    public function test_details_reach_stripe_in_its_own_shape(): void
    {
        $seller = Seller::factory()->approved()->create(['currency' => Currency::SEK]);
        PayoutAccount::factory()->for($seller)->create(['stripe_account_id' => 'acct_1Shop', 'country' => 'SE']);
        $stripe = $this->fakeStripe()
            ->respond('POST', '/v1/accounts/acct_1Shop', FakeStripe::accountInReview('acct_1Shop', last4: '0003'));

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->patchJson(self::ENDPOINT, [
                'first_name' => 'Aino',
                'date_of_birth' => '1990-04-07',
                'address' => ['line1' => 'Drottninggatan 1', 'city' => 'Stockholm', 'postal_code' => '111 51'],
                // Typed the way a bank prints it.
                'iban' => 'se35 5000 0000 0549 1000 0003',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.due', [])
            ->assertJsonPath('data.bank_account_last4', '0003');

        $sent = $stripe->sentTo('POST', '/v1/accounts/acct_1Shop');

        $this->assertSame('Aino', $sent['individual']['first_name']);
        $this->assertSame(['day' => 7, 'month' => 4, 'year' => 1990], $sent['individual']['dob']);
        $this->assertSame('Drottninggatan 1', $sent['individual']['address']['line1']);
        // The account's country, which the form does not ask for twice.
        $this->assertSame('SE', $sent['individual']['address']['country']);
        $this->assertSame([
            'object' => 'bank_account',
            'country' => 'SE',
            'currency' => 'sek',
            'account_number' => 'SE3550000000054910000003',
        ], $sent['external_account']);
        $this->assertArrayNotHasKey('tos_acceptance', $sent);
    }

    /**
     * The whole promise of the table: what a seller tells Stripe is not kept
     * here. The last four digits of the bank account are, and nothing else.
     */
    public function test_nothing_the_seller_sent_is_kept(): void
    {
        $seller = Seller::factory()->approved()->create(['currency' => Currency::EUR]);
        PayoutAccount::factory()->for($seller)->create(['stripe_account_id' => 'acct_1Shop']);
        $this->fakeStripe()->respond('POST', '/v1/accounts/acct_1Shop', FakeStripe::accountInReview('acct_1Shop'));

        $details = [
            'first_name' => 'Aino',
            'last_name' => 'Virtanen',
            'date_of_birth' => '1987-11-23',
            'id_number' => '231187-908L',
            'iban' => 'FI2112345600000785',
        ];

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->patchJson(self::ENDPOINT, $details)
            ->assertOk();

        $stored = json_encode(DB::table('payout_accounts')->first(), JSON_THROW_ON_ERROR);

        foreach (['Aino', 'Virtanen', '1987', '231187-908L', 'FI2112345600000785'] as $detail) {
            $this->assertStringNotContainsString($detail, $stored);
        }

        $this->assertStringContainsString('"bank_account_last4":"0785"', $stored);
    }

    public function test_stripe_refusing_a_detail_puts_its_reason_beside_that_field(): void
    {
        $account = PayoutAccount::factory()->create(['stripe_account_id' => 'acct_1Shop']);
        $this->fakeStripe()->refuse(
            'POST',
            '/v1/accounts/acct_1Shop',
            'external_account[account_number]',
            'The bank account number you provided is invalid.',
        );

        $this->actingAs($account->seller->user)
            ->fromFrontend()
            ->patchJson(self::ENDPOINT, ['iban' => 'FI2112345600000786'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['iban' => 'The bank account number you provided is invalid.']);
    }

    /**
     * Stripe objecting to a parameter no field sends is this application's
     * mistake. It is not put beside a field the seller cannot change - and the
     * details being sent do not ride along in the exception's trace, which is
     * where a log or an error tracker would copy them from.
     */
    public function test_a_refusal_this_api_caused_is_not_blamed_on_the_seller_and_carries_none_of_their_details(): void
    {
        $account = PayoutAccount::factory()->create(['stripe_account_id' => 'acct_1Shop']);
        $this->fakeStripe()->refuse('POST', '/v1/accounts/acct_1Shop', 'business_profile[mcc]', 'Invalid MCC.');

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($account->seller->user)
                ->fromFrontend()
                ->patchJson(self::ENDPOINT, ['iban' => 'FI2112345600000785', 'first_name' => 'Aino']);

            $this->fail('The refusal should have been rethrown as this application\'s own error.');
        } catch (InvalidRequestException $refusal) {
            $strings = [];
            $trace = $refusal->getTrace();

            array_walk_recursive($trace, static function (mixed $value) use (&$strings): void {
                if (is_string($value)) {
                    $strings[] = $value;
                }
            });

            $this->assertStringNotContainsString('FI2112345600000785', implode("\n", $strings));
            $this->assertStringNotContainsString('Aino', implode("\n", $strings));
        }
    }

    public function test_details_cannot_be_sent_before_an_account_is_opened(): void
    {
        $stripe = $this->fakeStripe();
        $seller = Seller::factory()->approved()->create();

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->patchJson(self::ENDPOINT, ['first_name' => 'Aino'])
            ->assertConflict()
            ->assertJsonPath('message', 'This shop has no payout account yet. Open one first.');

        $stripe->assertNothingSent();
    }

    public function test_an_empty_update_is_refused_rather_than_sent(): void
    {
        $stripe = $this->fakeStripe();
        $account = PayoutAccount::factory()->create();

        $this->actingAs($account->seller->user)
            ->fromFrontend()
            ->patchJson(self::ENDPOINT, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('details');

        $stripe->assertNothingSent();
    }

    public function test_an_iban_is_checked_for_shape_before_stripe_is_asked(): void
    {
        $stripe = $this->fakeStripe();
        $account = PayoutAccount::factory()->create();

        $this->actingAs($account->seller->user)
            ->fromFrontend()
            ->patchJson(self::ENDPOINT, ['iban' => 'my savings account'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('iban');

        $stripe->assertNothingSent();
    }

    /**
     * Stripe asks for its terms again when it changes them, and the newest
     * acceptance is the one that counts.
     */
    public function test_accepting_stripes_terms_again_records_who_accepted_and_from_where(): void
    {
        $account = PayoutAccount::factory()->create([
            'stripe_account_id' => 'acct_1Shop',
            'terms_accepted_ip' => '198.51.100.1',
            'requirements' => [
                'currently_due' => ['tos_acceptance.date', 'tos_acceptance.ip'],
                'past_due' => [],
                'errors' => [],
            ],
        ]);
        $stripe = $this->fakeStripe()->respond('POST', '/v1/accounts/acct_1Shop', FakeStripe::accountInReview('acct_1Shop'));

        $this->actingAs($account->seller->user)
            ->getJson(self::ENDPOINT)
            ->assertJsonPath('data.due', ['terms']);

        $this->actingAs($account->seller->user)
            ->fromFrontend()
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->patchJson(self::ENDPOINT, ['terms' => true])
            ->assertOk();

        $this->assertSame('203.0.113.9', $stripe->sentTo('POST', '/v1/accounts/acct_1Shop')['tos_acceptance']['ip']);
        $this->assertSame('203.0.113.9', $account->refresh()->terms_accepted_ip);
    }

    public function test_the_status_follows_what_stripe_last_said(): void
    {
        $accounts = [
            'action_required' => PayoutAccount::factory()->create(),
            'in_review' => PayoutAccount::factory()->inReview()->create(),
            'active' => PayoutAccount::factory()->active()->create(),
            'rejected' => PayoutAccount::factory()->rejected()->create(),
        ];

        foreach ($accounts as $status => $account) {
            $this->actingAs($account->seller->user)
                ->getJson(self::ENDPOINT)
                ->assertJsonPath('data.status', $status);
        }
    }

    public function test_what_stripe_could_not_verify_is_put_beside_its_field(): void
    {
        $account = PayoutAccount::factory()->create([
            'requirements' => [
                'currently_due' => ['individual.verification.document'],
                'past_due' => [],
                'errors' => [[
                    'requirement' => 'individual.verification.document',
                    'code' => 'verification_document_failed_other',
                    'reason' => 'The document could not be read.',
                ]],
            ],
        ]);

        $this->actingAs($account->seller->user)
            ->getJson(self::ENDPOINT)
            ->assertJsonPath('data.due', ['identity_document'])
            ->assertJsonPath('data.errors.0.field', 'identity_document')
            ->assertJsonPath('data.errors.0.reason', 'The document could not be read.');
    }
}
