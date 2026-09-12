<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an order's payment stands.
 *
 * ```text
 * Pending ──confirmed──▶ RequiresAction ──authenticated──▶ Processing ──▶ Succeeded
 *    │                        │                                │
 *    └────────────────────────┴──refused or abandoned──────────┴──▶ Failed
 *    │
 *    └──order cancelled before it was paid──▶ Cancelled
 * ```
 *
 * **This is not `orders.status`, and it must not become one** (ADR 0015). An
 * order's status is about fulfilment - whether a shop accepted it, sent it,
 * completed it - and stays that way. Whether the money arrived is a different
 * question with a different answer, and `paid` was deliberately never added to
 * the other enum.
 *
 * **Stripe has no `failed` status**, and that is the one case here that is not
 * a rename. A refused card leaves the intent at `requires_payment_method` with
 * a `last_payment_error`, which is indistinguishable from an intent nobody has
 * tried to pay yet unless the error is read. The two mean very different things
 * to a buyer looking at their order, so they are two cases here.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * The domain's word for what Stripe last said about an intent.
     *
     * `$refused` is whether the intent carries a `last_payment_error`, which is
     * what separates "nobody has paid yet" from "a card was declined".
     */
    public static function forIntent(string $stripeStatus, bool $refused = false): self
    {
        return match ($stripeStatus) {
            'requires_action', 'requires_confirmation' => self::RequiresAction,
            'processing' => self::Processing,
            'succeeded' => self::Succeeded,
            'canceled' => self::Cancelled,
            'requires_payment_method' => $refused ? self::Failed : self::Pending,
            default => self::Pending,
        };
    }

    /** Whether the money is on the platform and the order can be worked on. */
    public function isPaid(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Whether anything more is going to happen to this payment on its own.
     * A failed one waits for another attempt; a cancelled one does not.
     */
    public function isSettled(): bool
    {
        return $this === self::Succeeded || $this === self::Cancelled;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
