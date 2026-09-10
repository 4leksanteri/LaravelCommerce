<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_anonymous_caller_is_refused_with_401(): void
    {
        // 401 and not 403. The frontend ends a session on the first and
        // explains a refusal on the second, so answering the wrong one makes
        // one of those behaviours wrong.
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /**
     * `get`, not `getJson`, and that is the whole point of the test.
     *
     * getJson sets Accept: application/json. Laravel's Authenticate middleware
     * branches on that header to decide between redirecting to a sign-in page
     * and throwing, and it does so before the exception handler - and its
     * configured handler - is consulted at all. With the framework default in
     * place this endpoint answered every client that did not ask for JSON with
     * a 500 "Route [login] not defined", while the test above passed.
     *
     * There is no sign-in page here. Every refusal is a status code.
     */
    public function test_it_refuses_without_content_negotiation_too(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_it_returns_the_user_the_session_belongs_to(): void
    {
        $user = User::factory()->create([
            'name' => 'Aino Virtanen',
            'email' => 'aino@example.com',
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Aino Virtanen')
            ->assertJsonPath('data.email', 'aino@example.com');
    }

    /**
     * The resource is an allowlist. A column added to the users table must not
     * reach a client because nobody remembered to exclude it.
     */
    public function test_it_never_publishes_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/api/v1/auth/me');

        $response->assertOk();

        $this->assertSame(
            ['id', 'name', 'email', 'email_verified_at', 'created_at', 'can_review_sellers'],
            array_keys($response->json('data')),
        );
    }

    /**
     * The browser gets its CSRF token here, and the address matters: it is
     * under /api/v1, which is the only prefix the Next.js server proxies. If
     * this route moves back to Sanctum's default /sanctum prefix, every unsafe
     * request from the browser starts failing with a 419.
     */
    public function test_the_csrf_cookie_is_issued_under_the_api_prefix(): void
    {
        $this->get('/api/v1/auth/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }
}
