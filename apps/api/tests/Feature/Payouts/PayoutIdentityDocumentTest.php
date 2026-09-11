<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Models\PayoutAccount;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakesStripe;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * An identity document, passed through to Stripe and not kept (ADR 0031).
 */
final class PayoutIdentityDocumentTest extends TestCase
{
    use FakesStripe;
    use RefreshDatabase;

    private const string ENDPOINT = '/api/v1/seller/payout-account/identity-document';

    public function test_a_document_goes_to_stripe_as_the_sellers_account_and_is_attached(): void
    {
        $account = PayoutAccount::factory()->create(['stripe_account_id' => 'acct_1Shop']);
        $stripe = $this->fakeStripe()
            ->respond('POST', '/v1/files', ['id' => 'file_front', 'object' => 'file', 'purpose' => 'identity_document'])
            ->respond('POST', '/v1/files', ['id' => 'file_back', 'object' => 'file', 'purpose' => 'identity_document'])
            ->respond('POST', '/v1/accounts/acct_1Shop', FakeStripe::accountInReview('acct_1Shop'));

        $this->actingAs($account->seller->user)
            ->fromFrontend()
            ->post(self::ENDPOINT, [
                'front' => UploadedFile::fake()->image('front.jpg'),
                'back' => UploadedFile::fake()->image('back.png'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review');

        $this->assertSame(2, $stripe->timesSentTo('POST', '/v1/files'));
        $this->assertSame('identity_document', $stripe->sentTo('POST', '/v1/files')['purpose']);
        // The seller's document, uploaded as the seller's account.
        $this->assertContains('Stripe-Account: acct_1Shop', $stripe->headersSentTo('POST', '/v1/files'));
        $this->assertSame(
            ['front' => 'file_front', 'back' => 'file_back'],
            $stripe->sentTo('POST', '/v1/accounts/acct_1Shop')['individual']['verification']['document'],
        );
    }

    public function test_the_back_is_optional_because_a_passport_has_none(): void
    {
        $account = PayoutAccount::factory()->create(['stripe_account_id' => 'acct_1Shop']);
        $stripe = $this->fakeStripe()
            ->respond('POST', '/v1/files', ['id' => 'file_passport', 'object' => 'file', 'purpose' => 'identity_document'])
            ->respond('POST', '/v1/accounts/acct_1Shop', FakeStripe::accountInReview('acct_1Shop'));

        $this->actingAs($account->seller->user)
            ->fromFrontend()
            ->post(self::ENDPOINT, ['front' => UploadedFile::fake()->image('passport.jpg')], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(
            ['front' => 'file_passport'],
            $stripe->sentTo('POST', '/v1/accounts/acct_1Shop')['individual']['verification']['document'],
        );
    }

    /**
     * Named like a photograph and sent as one, and a text file all the same.
     * The rule reads what the file is rather than what it claims to be.
     *
     * A real file, not `UploadedFile::fake()`: Laravel's fake reports its type
     * from the name it was given, so a fake called passport.jpg is a JPEG
     * whatever is in it, and this passed against a rule that read nothing.
     */
    public function test_only_an_image_or_a_pdf_is_accepted_whatever_it_claims_to_be(): void
    {
        $stripe = $this->fakeStripe();
        $account = PayoutAccount::factory()->create();

        $path = (string) tempnam(sys_get_temp_dir(), 'document');
        file_put_contents($path, 'not a photograph of anything');

        try {
            $this->actingAs($account->seller->user)
                ->fromFrontend()
                ->post(self::ENDPOINT, [
                    'front' => new UploadedFile($path, 'passport.jpg', 'image/jpeg', null, true),
                ], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('front');
        } finally {
            @unlink($path);
        }

        $stripe->assertNothingSent();
    }

    public function test_stripe_refusing_a_file_puts_its_reason_beside_that_side(): void
    {
        $account = PayoutAccount::factory()->create(['stripe_account_id' => 'acct_1Shop']);
        $this->fakeStripe()
            ->respond('POST', '/v1/files', ['id' => 'file_front', 'object' => 'file', 'purpose' => 'identity_document'])
            ->refuse('POST', '/v1/files', 'file', 'The image is too small to read.');

        $this->actingAs($account->seller->user)
            ->fromFrontend()
            ->post(self::ENDPOINT, [
                'front' => UploadedFile::fake()->image('front.jpg'),
                'back' => UploadedFile::fake()->image('back.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['back' => 'The image is too small to read.']);
    }

    public function test_a_document_cannot_be_sent_before_an_account_is_opened(): void
    {
        $stripe = $this->fakeStripe();
        $seller = Seller::factory()->approved()->create();

        $this->actingAs($seller->user)
            ->fromFrontend()
            ->post(self::ENDPOINT, ['front' => UploadedFile::fake()->image('front.jpg')], ['Accept' => 'application/json'])
            ->assertConflict();

        $stripe->assertNothingSent();
    }
}
