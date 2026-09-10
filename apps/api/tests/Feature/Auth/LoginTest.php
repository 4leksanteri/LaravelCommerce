<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class LoginTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create([
            'email' => 'aino@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    public function test_it_signs_a_person_in(): void
    {
        $user = $this->user();

        $this->fromFrontend()
            ->postJson('/api/v1/auth/login', [
                'email' => 'aino@example.com',
                'password' => 'correct-horse-battery',
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_address_is_not_case_sensitive(): void
    {
        $user = $this->user();

        $this->fromFrontend()
            ->postJson('/api/v1/auth/login', [
                'email' => 'AINO@Example.com',
                'password' => 'correct-horse-battery',
            ])
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }

    /**
     * The point of this test is the assertion that the two responses are
     * identical, not that each of them fails.
     *
     * An endpoint that answers differently for an address with an account and
     * one without is a way to find out who has an account here. For a
     * marketplace that discloses who sells and who buys, so the two paths have
     * to be indistinguishable from outside. See ADR 0002.
     */
    public function test_an_unknown_address_and_a_wrong_password_are_indistinguishable(): void
    {
        $this->user();

        $unknown = $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $wrongPassword = $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => 'aino@example.com',
            'password' => 'not-the-right-one',
        ]);

        $unknown->assertUnprocessable();
        $wrongPassword->assertUnprocessable();

        $this->assertSame(
            $unknown->json(),
            $wrongPassword->json(),
            'Login must answer identically whether or not the address has an account.',
        );

        $this->assertGuest();
    }

    /**
     * Session fixation: an id planted before sign-in must not still be valid
     * after it.
     */
    public function test_it_regenerates_the_session_id(): void
    {
        $this->user();

        $this->fromFrontend()->get('/api/v1/auth/csrf-cookie');
        $before = session()->getId();

        $this->fromFrontend()->postJson('/api/v1/auth/login', [
            'email' => 'aino@example.com',
            'password' => 'correct-horse-battery',
        ])->assertOk();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_it_is_rate_limited(): void
    {
        $this->user();

        // The limiter allows five a minute for one address from one address.
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->fromFrontend()->postJson('/api/v1/auth/login', [
                'email' => 'aino@example.com',
                'password' => 'wrong',
            ])->assertUnprocessable();
        }

        $this->fromFrontend()
            ->postJson('/api/v1/auth/login', [
                'email' => 'aino@example.com',
                'password' => 'wrong',
            ])
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    /**
     * A password that no longer meets the current policy must still sign in.
     * Applying Password::defaults() to this endpoint would refuse it, telling
     * the person their own password is invalid.
     */
    public function test_it_accepts_a_password_that_predates_the_current_policy(): void
    {
        $user = User::factory()->create([
            'email' => 'old@example.com',
            'password' => Hash::make('short1'),
        ]);

        $this->fromFrontend()
            ->postJson('/api/v1/auth/login', ['email' => 'old@example.com', 'password' => 'short1'])
            ->assertOk();

        $this->assertAuthenticatedAs($user);
    }
}
