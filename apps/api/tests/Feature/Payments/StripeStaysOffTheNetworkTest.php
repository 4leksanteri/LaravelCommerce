<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use Stripe\ApiRequestor;
use Tests\Support\FakeStripe;
use Tests\TestCase;

/**
 * The suite does not talk to Stripe, and no test has to remember to say so.
 *
 * This is a regression rather than a tidiness rule. The suite ran against
 * whichever test account the developer had configured, which made it both a
 * writer of real objects and non-deterministic: `OpenPaymentsForCheckout`
 * keys its customer creation on the buyer's id, `RefreshDatabase` reuses ids,
 * and Stripe refuses an idempotency key reused with different parameters. A
 * run therefore succeeded at Stripe for some buyers and failed for others, and
 * the orders whose checkout succeeded got a payment row the order fixtures
 * wrote again - seven errors that moved between runs and vanished when the
 * same suite was run twice (ADR 0042).
 *
 * Neither assertion needs a fake of its own, which is the point of both.
 */
final class StripeStaysOffTheNetworkTest extends TestCase
{
    public function test_a_test_that_never_asked_for_a_fake_still_cannot_reach_stripe(): void
    {
        $this->assertInstanceOf(FakeStripe::class, ApiRequestor::httpClient());
    }

    /** Whatever Compose passed this container, the suite does not use it. */
    public function test_the_suite_runs_on_a_key_that_belongs_to_nobody(): void
    {
        $this->assertSame('sk_test_suite', config('services.stripe.secret'));
        $this->assertSame('whsec_suite', config('services.stripe.webhook_secret'));
    }
}
