<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\PaymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Stripe's words for where an intent has got to, in this domain's (ADR 0040).
 *
 * Worth its own test because one case is not a rename: Stripe has no `failed`
 * status, and a refused card is `requires_payment_method` with an error on it -
 * the same status as an intent nobody has tried to pay. Getting that wrong
 * shows a buyer whose card was declined a page that says nothing happened.
 */
final class PaymentStatusTest extends TestCase
{
    public function test_stripes_statuses_become_this_domains(): void
    {
        $this->assertSame(PaymentStatus::Pending, PaymentStatus::forIntent('requires_payment_method'));
        $this->assertSame(PaymentStatus::RequiresAction, PaymentStatus::forIntent('requires_action'));
        $this->assertSame(PaymentStatus::RequiresAction, PaymentStatus::forIntent('requires_confirmation'));
        $this->assertSame(PaymentStatus::Processing, PaymentStatus::forIntent('processing'));
        $this->assertSame(PaymentStatus::Succeeded, PaymentStatus::forIntent('succeeded'));
        $this->assertSame(PaymentStatus::Cancelled, PaymentStatus::forIntent('canceled'));
    }

    /**
     * The one that is not a rename. The status is identical; the error is the
     * whole difference.
     */
    public function test_a_refused_card_is_told_apart_from_an_untouched_intent(): void
    {
        $this->assertSame(
            PaymentStatus::Pending,
            PaymentStatus::forIntent('requires_payment_method', refused: false),
        );

        $this->assertSame(
            PaymentStatus::Failed,
            PaymentStatus::forIntent('requires_payment_method', refused: true),
        );
    }

    /** A status Stripe adds is pending here rather than a crash. */
    public function test_a_status_this_application_does_not_know_waits(): void
    {
        $this->assertSame(PaymentStatus::Pending, PaymentStatus::forIntent('something_new'));
    }

    public function test_only_a_succeeded_payment_is_paid(): void
    {
        $paid = array_filter(PaymentStatus::cases(), static fn (PaymentStatus $s): bool => $s->isPaid());

        $this->assertSame([PaymentStatus::Succeeded], array_values($paid));
    }

    /**
     * Settled means nothing more happens on its own. A failed payment is not
     * settled: the buyer can try another card.
     */
    public function test_what_is_settled_and_what_still_might_move(): void
    {
        $this->assertTrue(PaymentStatus::Succeeded->isSettled());
        $this->assertTrue(PaymentStatus::Cancelled->isSettled());

        $this->assertFalse(PaymentStatus::Failed->isSettled());
        $this->assertFalse(PaymentStatus::Pending->isSettled());
        $this->assertFalse(PaymentStatus::RequiresAction->isSettled());
        $this->assertFalse(PaymentStatus::Processing->isSettled());
    }
}
