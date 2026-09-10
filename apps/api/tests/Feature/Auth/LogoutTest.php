<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertGuest();
    }

    public function test_an_anonymous_caller_is_refused(): void
    {
        $this->fromFrontend()
            ->postJson('/api/v1/auth/logout')
            ->assertUnauthorized();
    }

    /**
     * The CSRF token belongs to the session that just ended. Leaving the old
     * one in place would let the next write be made with a token issued to a
     * session nobody is in any more.
     */
    public function test_it_issues_a_new_csrf_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->fromFrontend()->get('/api/v1/auth/csrf-cookie');
        $before = session()->token();

        $this->actingAs($user)
            ->fromFrontend()
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();

        $this->assertNotSame($before, session()->token());
    }
}
