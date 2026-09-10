<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class VerifyEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pulls the four values out of the link the notification actually built.
     *
     * Every test here starts from the real notification rather than from
     * URL::temporarySignedRoute, because the thing most likely to break is the
     * translation between the two: the link goes to the frontend, and the
     * frontend has to be able to rebuild the API path from what the link
     * carries. A test that signed its own URL would pass while every link in
     * every inbox was useless.
     *
     * @return array{id: string, hash: string, expires: string, signature: string}
     */
    private function linkParameters(User $user): array
    {
        $captured = null;

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$captured): bool {
            $captured = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->assertIsString($captured);

        $this->assertStringStartsWith(
            rtrim((string) config('app.frontend_url'), '/').'/verify-email?',
            $captured,
            'The verification link must point at the frontend. The API is not reachable from a browser.',
        );

        parse_str((string) parse_url($captured, PHP_URL_QUERY), $query);

        foreach (['id', 'hash', 'expires', 'signature'] as $key) {
            $this->assertArrayHasKey($key, $query, "The link must carry {$key}.");
        }

        /** @var array{id: string, hash: string, expires: string, signature: string} $query */
        return $query;
    }

    /**
     * The order of the query string is load-bearing, not cosmetic. Laravel
     * signs the raw query string, so `expires` has to come before `signature`
     * exactly as it did when the URL was generated.
     *
     * @param  array{id: string, hash: string, expires: string, signature: string}  $query
     */
    private function apiUrl(array $query): string
    {
        return sprintf(
            '/api/v1/auth/email/verify/%s/%s?expires=%s&signature=%s',
            $query['id'],
            $query['hash'],
            $query['expires'],
            $query['signature'],
        );
    }

    public function test_registering_sends_a_verification_link(): void
    {
        Notification::fake();

        $this->fromFrontend()->postJson('/api/v1/auth/register', [
            'name' => 'Aino Virtanen',
            'email' => 'aino@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertCreated();

        $user = User::firstWhere('email', 'aino@example.com');
        $this->assertNotNull($user);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /**
     * The whole round trip, exactly as the frontend will perform it: read the
     * four values out of the link, rebuild the API path, call it.
     */
    public function test_the_emailed_link_verifies_the_address(): void
    {
        Notification::fake();
        Event::fake([Verified::class]);

        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $this->assertFalse($user->hasVerifiedEmail());

        $this->getJson($this->apiUrl($this->linkParameters($user)))
            ->assertOk()
            ->assertJsonPath('verified', true)
            ->assertJsonPath('already_verified', false);

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());

        Event::assertDispatched(Verified::class);
    }

    /**
     * It does not require a session, deliberately. Mail is very often opened
     * on a different device from the one somebody registered on, and a link
     * that only worked in the original browser would strand them.
     */
    public function test_it_works_without_being_signed_in(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $this->assertGuest();

        $this->getJson($this->apiUrl($this->linkParameters($user)))->assertOk();

        $this->assertTrue($user->fresh()?->hasVerifiedEmail());
    }

    /**
     * Mail clients prefetch links and people click twice. A second visit must
     * read as success, not as a failure.
     */
    public function test_visiting_the_link_twice_is_not_an_error(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $url = $this->apiUrl($this->linkParameters($user));

        $this->getJson($url)->assertOk()->assertJsonPath('already_verified', false);
        $this->getJson($url)->assertOk()->assertJsonPath('already_verified', true);
    }

    public function test_it_refuses_a_link_whose_signature_was_tampered_with(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $query = $this->linkParameters($user);
        $query['signature'] = str_repeat('0', mb_strlen($query['signature']));

        $this->getJson($this->apiUrl($query))->assertForbidden();

        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    /**
     * The hash is over the address. Changing it, or aiming the link at another
     * account, has to fail even though the signature is untouched.
     */
    public function test_it_refuses_a_hash_that_is_not_for_that_address(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $query = $this->linkParameters($user);
        $query['hash'] = sha1('somebody.else@example.com');

        // The signature covers the path, and the hash is in the path, so a
        // changed hash fails the signature check before the controller runs.
        $this->getJson($this->apiUrl($query))->assertForbidden();

        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_it_refuses_an_expired_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $url = $this->apiUrl($this->linkParameters($user));

        $this->travelTo(Carbon::now()->addMinutes((int) config('auth.verification.expire', 60) + 1));

        $this->getJson($url)->assertForbidden();

        $this->assertFalse($user->fresh()?->hasVerifiedEmail());
    }

    public function test_a_signed_in_person_can_ask_for_another_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/auth/email/resend')
            ->assertNoContent();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resending_for_a_verified_account_sends_nothing(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/auth/email/resend')
            ->assertNoContent();

        Notification::assertNothingSent();
    }

    /**
     * There is no address field on the resend endpoint, and it is behind the
     * guard. Together that stops it being a way to send mail to an arbitrary
     * address from this application's domain.
     */
    public function test_an_anonymous_caller_cannot_trigger_mail(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/email/resend')->assertUnauthorized();

        Notification::assertNothingSent();
    }
}
