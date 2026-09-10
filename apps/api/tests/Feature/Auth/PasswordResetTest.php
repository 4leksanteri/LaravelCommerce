<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The important assertion is that the two responses are byte-identical.
     *
     * If asking for a reset link answered differently for an address with an
     * account and one without, the endpoint would be a way to enumerate who
     * has an account - which the login endpoint already refuses to be, so
     * leaking it here would make that protection pointless.
     */
    public function test_it_answers_identically_for_a_known_and_an_unknown_address(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'aino@example.com']);

        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'aino@example.com']);
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();

        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame($known->status(), $unknown->status());
    }

    public function test_it_sends_a_link_only_to_an_address_that_has_an_account(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'aino@example.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'aino@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    /**
     * The link goes to the Next.js application, never to this one. A link
     * pointing at the API would be a dead link in somebody's inbox, because
     * the API is not reachable from a browser.
     */
    public function test_the_emailed_link_points_at_the_frontend(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'aino@example.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'aino@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringStartsWith(
                rtrim((string) config('app.frontend_url'), '/').'/reset-password?',
                (string) $url,
            );

            parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

            $this->assertArrayHasKey('token', $query);
            $this->assertSame('aino@example.com', $query['email']);

            return true;
        });
    }

    /**
     * The whole round trip, driven by the token the notification actually
     * carried, in the order the frontend will do it. A test that reaches for
     * Password::createToken() instead would pass even if the emailed link
     * carried something else entirely.
     */
    public function test_a_person_can_reset_their_password_with_the_emailed_token(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'aino@example.com',
            'password' => Hash::make('the-old-password'),
        ]);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'aino@example.com'])->assertOk();

        $token = null;

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->assertIsString($token);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'aino@example.com',
            'password' => 'a-completely-new-one',
            'password_confirmation' => 'a-completely-new-one',
        ])->assertNoContent();

        $user->refresh();

        $this->assertTrue(Hash::check('a-completely-new-one', $user->password));
        $this->assertFalse(Hash::check('the-old-password', $user->password));
    }

    /**
     * The password is stored through the model's `hashed` cast. Hashing it in
     * the callback as well would store a hash of a hash, and the new password
     * would never work - a bug that only shows up on the next sign-in.
     */
    public function test_the_new_password_can_actually_sign_in(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'aino@example.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'aino@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => 'aino@example.com',
            'password' => 'a-completely-new-one',
            'password_confirmation' => 'a-completely-new-one',
        ])->assertNoContent();

        $this->fromFrontend()
            ->postJson('/api/v1/auth/login', [
                'email' => 'aino@example.com',
                'password' => 'a-completely-new-one',
            ])
            ->assertOk();
    }

    public function test_it_refuses_a_token_that_was_never_issued(): void
    {
        User::factory()->create(['email' => 'aino@example.com']);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'not-a-real-token',
            'email' => 'aino@example.com',
            'password' => 'a-completely-new-one',
            'password_confirmation' => 'a-completely-new-one',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_token_cannot_be_used_twice(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'aino@example.com']);

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'aino@example.com'])->assertOk();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $payload = [
            'token' => $token,
            'email' => 'aino@example.com',
            'password' => 'a-completely-new-one',
            'password_confirmation' => 'a-completely-new-one',
        ];

        $this->postJson('/api/v1/auth/password/reset', $payload)->assertNoContent();
        $this->postJson('/api/v1/auth/password/reset', $payload)->assertUnprocessable();
    }

    public function test_it_refuses_a_new_password_that_does_not_meet_the_policy(): void
    {
        User::factory()->create(['email' => 'aino@example.com']);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'irrelevant',
            'email' => 'aino@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }
}
