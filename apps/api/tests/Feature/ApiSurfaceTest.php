<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The shape of the public surface, asserted rather than described.
 *
 * The Next.js server proxies /api/** and nothing else (ADR 0003). A route
 * outside that prefix is not a security hole - nothing forwards to it - but it
 * is dead surface: reachable only from inside the Docker network, by nothing,
 * and quietly untested. It is usually a package publishing a route nobody
 * asked for.
 *
 * When this fails, the question is which client is meant to call the new
 * route. If the answer is the browser, it belongs under api/v1. If the answer
 * is nothing, turn it off where it is registered.
 */
final class ApiSurfaceTest extends TestCase
{
    /**
     * The one deliberate exception. Docker's health check runs inside the
     * container and does not go through the proxy, so it does not need to
     * track an API version.
     */
    private const array UNVERSIONED_PROBES = ['up'];

    public function test_every_route_is_reachable_through_the_proxy(): void
    {
        $stranded = collect(Route::getRoutes()->getRoutes())
            ->map(static fn (RoutingRoute $route): string => $route->uri())
            ->reject(static fn (string $uri): bool => str_starts_with($uri, 'api/v1/'))
            ->reject(static fn (string $uri): bool => in_array($uri, self::UNVERSIONED_PROBES, true))
            ->unique()
            ->values()
            ->all();

        $this->assertSame([], $stranded, sprintf(
            'These routes are outside api/v1 and nothing proxies to them: %s',
            implode(', ', $stranded),
        ));
    }
}
