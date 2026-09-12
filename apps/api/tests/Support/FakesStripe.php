<?php

declare(strict_types=1);

namespace Tests\Support;

use Stripe\ApiRequestor;

/**
 * Answers Stripe for one test.
 *
 * `TestCase` has already put a FakeStripe with nothing queued where the SDK's
 * network client goes, so a test that does not use this trait cannot reach
 * Stripe either. This replaces that one with an instance the test holds, and
 * can queue answers on and assert against.
 *
 * The keys are fake and shaped like test keys, because the application refuses
 * anything else (AppServiceProvider::bindStripe).
 *
 * **Nothing puts the real network client back.** The SDK keeps its HTTP client
 * in a static that outlives the application a test boots, and restoring
 * `CurlClient` here would leave the suite one forgotten `fakeStripe()` away
 * from opening real intents. The next test's `setUp` installs its own fake, so
 * no test inherits this one's answers.
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

        return $stripe;
    }
}
