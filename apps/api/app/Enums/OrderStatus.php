<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an order is.
 *
 * ```text
 * Pending ──accept──▶ Accepted ──ship──▶ Shipped ──confirm──▶ Completed
 *    │                    │                  │        or the deadline passes
 *    │                    └──seller──────────┤
 *    └──either party cancels─────────────────┴──▶ Cancelled
 * ```
 *
 * Five cases, and each is reachable: an enum case nothing can arrive at is a
 * rule that reads as though it applies when nothing applies it.
 *
 * Three things worth knowing about the shape:
 *
 * **A buyer may only cancel while nobody has committed.** Once a seller has
 * accepted, they may have bought materials or set aside stock, and calling it
 * off is no longer the buyer's alone to do. The seller can still cancel then -
 * they are the one who would be let down by it.
 *
 * **Only the buyer completes**, or the clock does on their behalf. Completion
 * is the buyer saying they got what they paid for, and it is what will
 * eventually release a payout. A seller who could complete their own order
 * could declare their own payout releasable, which is the one thing an escrow
 * marketplace exists to prevent - so the only other thing that may complete one
 * is `orders:auto-complete`, on a deadline the buyer can push back (ADR 0014).
 *
 * **Cancelled and Completed are final.** Nothing moves afterwards. Getting a
 * completed order unwound is a dispute, and disputes are deliberately not
 * built.
 *
 * The transitions themselves live in `App\Actions\Orders`, not here. This enum
 * says which are legal; the actions do them, and carry the side effects - a
 * cancellation gives the stock back.
 */
enum OrderStatus: string
{
    /** Placed, and waiting for the seller. There is no payment system yet. */
    case Pending = 'pending';

    /** The seller has committed to fulfilling it. */
    case Accepted = 'accepted';

    /** On its way. */
    case Shipped = 'shipped';

    /** The buyer has confirmed they received it. Final. */
    case Completed = 'completed';

    /** Called off by one party or the other, and the stock given back. Final. */
    case Cancelled = 'cancelled';

    /** Whether anything can still happen to an order in this state. */
    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Cancelled;
    }

    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * The asymmetry that matters.
     *
     * A buyer may call off an order nobody has committed to. After that only
     * the seller may, because they are the party who set stock aside, started
     * work and eventually put it in the post.
     *
     * **A seller may cancel a shipped order**, and it is the escape hatch for
     * a parcel that never arrives. Without it a lost shipment would sit in
     * `shipped` until auto-completion declared it received (ADR 0014).
     *
     * Cancelling one does **not** give the stock back. The goods left the
     * building; a shop that has posted something does not still have it, and
     * saying otherwise sells it twice. `CancelOrder` holds that rule.
     */
    public function canBeCancelledBy(OrderParty $party): bool
    {
        return match ($this) {
            self::Pending => true,
            self::Accepted, self::Shipped => $party === OrderParty::Seller,
            default => false,
        };
    }

    /**
     * Whether the goods have left the shop.
     *
     * Read by `CancelOrder` to decide whether stock comes back, which is the
     * one question that separates a cancellation before shipping from one
     * after it.
     */
    public function hasShipped(): bool
    {
        return $this === self::Shipped;
    }

    /**
     * Whether the buyer may push back the date this completes on its own.
     *
     * The same condition as `canBeCompleted()` today, and deliberately its own
     * method rather than a reuse of it - they answer different questions and
     * will stop agreeing the moment anything else can be completed.
     */
    public function canHaveDeadlineExtended(): bool
    {
        return $this === self::Shipped;
    }

    public function canBeAccepted(): bool
    {
        return $this === self::Pending;
    }

    public function canBeShipped(): bool
    {
        return $this === self::Accepted;
    }

    public function canBeCompleted(): bool
    {
        return $this === self::Shipped;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
