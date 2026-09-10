<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_is_public(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    /**
     * The probe must not depend on anything it does not need. No database
     * connection is configured for this test, so a health check that touched
     * one would fail here - which is the point: a dependency check inside a
     * liveness probe takes a healthy process out of rotation for a problem
     * somewhere else.
     */
    public function test_health_does_not_touch_the_database(): void
    {
        config(['database.default' => 'no-such-connection']);

        $this->getJson('/api/v1/health')->assertOk();
    }
}
