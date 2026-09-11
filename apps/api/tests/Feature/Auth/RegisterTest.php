<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class RegisterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Aino Virtanen',
            'email' => 'aino@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ], $overrides);
    }

    public function test_it_creates_an_account_and_signs_the_person_in(): void
    {
        Notification::fake();

        $response = $this->fromFrontend()->postJson('/api/v1/auth/register', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'aino@example.com')
            ->assertJsonPath('data.email_verified_at', null);

        $user = User::firstWhere('email', 'aino@example.com');
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_it_never_returns_the_password_hash(): void
    {
        Notification::fake();

        $response = $this->fromFrontend()->postJson('/api/v1/auth/register', $this->validPayload());

        $this->assertSame(
            ['id', 'name', 'email', 'email_verified_at', 'created_at', 'can_review_sellers', 'has_shop'],
            array_keys($response->json('data')),
        );
    }

    public function test_the_password_is_hashed_and_not_stored_as_typed(): void
    {
        Notification::fake();

        $this->fromFrontend()->postJson('/api/v1/auth/register', $this->validPayload());

        $user = User::firstWhere('email', 'aino@example.com');

        $this->assertNotNull($user);
        $this->assertNotSame('correct-horse-battery', $user->password);
        $this->assertTrue(password_verify('correct-horse-battery', $user->password));
    }

    /**
     * Addresses are lowered before validation, not after. Doing it after would
     * let `unique` compare the raw input against stored values, and two
     * accounts could then exist for what is one mailbox.
     */
    public function test_it_stores_the_address_lowercased(): void
    {
        Notification::fake();

        $this->fromFrontend()
            ->postJson('/api/v1/auth/register', $this->validPayload(['email' => '  Aino@Example.COM  ']))
            ->assertCreated();

        $this->assertNotNull(User::firstWhere('email', 'aino@example.com'));
    }

    public function test_it_refuses_an_address_that_differs_only_by_case(): void
    {
        User::factory()->create(['email' => 'aino@example.com']);

        $this->fromFrontend()
            ->postJson('/api/v1/auth/register', $this->validPayload(['email' => 'AINO@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_it_refuses_a_password_shorter_than_the_policy(): void
    {
        $this->fromFrontend()
            ->postJson('/api/v1/auth/register', $this->validPayload([
                'password' => 'short',
                'password_confirmation' => 'short',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertGuest();
    }

    public function test_it_refuses_a_mistyped_confirmation(): void
    {
        $this->fromFrontend()
            ->postJson('/api/v1/auth/register', $this->validPayload([
                'password_confirmation' => 'something-else-entirely',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    /**
     * bcrypt hashes the first 72 bytes and PHP throws beyond that. Refusing in
     * validation turns a 500 into a field error.
     */
    public function test_it_refuses_a_password_longer_than_bcrypt_accepts(): void
    {
        $long = str_repeat('a', 73);

        $this->fromFrontend()
            ->postJson('/api/v1/auth/register', $this->validPayload([
                'password' => $long,
                'password_confirmation' => $long,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    /**
     * Without an Origin the request is not stateful, there is no session, and
     * signing somebody in is not possible. The `stateful` middleware turns
     * what would be a 500 about a missing session store into a 400 that says
     * what happened.
     */
    public function test_it_refuses_a_request_that_cannot_hold_a_session(): void
    {
        $this->postJson('/api/v1/auth/register', $this->validPayload())
            ->assertStatus(400);

        $this->assertGuest();
    }
}
