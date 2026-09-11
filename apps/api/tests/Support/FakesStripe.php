<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

/**
 * Puts FakeStripe where the SDK's network client goes, for one test.
 *
 * The keys are fake and shaped like test keys, because the application refuses
 * anything else (AppServiceProvider::bindStripe). The SDK keeps its HTTP client
 * in a static that outlives the application a test boots, so the real one is
 * put back afterwards and no later test inherits this one's answers.
 */
trait FakesStripe
{
    protected function fakeStripe(): FakeStripe
    {
        config([
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => 'whsec_fake',
        ]);

        $stripe = new FakeStripe;

        ApiRequestor::setHttpClient($stripe);

        $this->beforeApplicationDestroyed(static function (): void {
            ApiRequestor::setHttpClient(CurlClient::instance());
        });

        return $stripe;
    }
}
