<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * An account that cannot be closed yet (ADR 0058).
 *
 * **409 in every case, never 403.** Somebody is entitled to close their own
 * account - it is theirs - and what is in the way is that the marketplace still
 * owes something to somebody, or somebody still owes something to them. That is
 * the state of the world rather than a question about who is asking (ADR 0008).
 *
 * Each of these is temporary and each names what to do about it, which is the
 * difference between a refusal somebody can act on and one that reads as a
 * wall. Nothing here is permanent: every one of them ends when the orders end.
 *
 * Not reported to the log, like every `DomainRefusal` (ADR 0045).
 */
final class AccountNotCloseableException extends DomainRefusal
{
    /**
     * An order was called off and the money has not come back yet.
     *
     * **Deliberately not every held payment.** A completed order whose shop has
     * no active payout account also sits paid-and-untransferred until a
     * settlement run collects it, and refusing on that would hold a buyer who
     * has confirmed their parcel hostage to business they have no stake in. The
     * case worth refusing for is the one where they are owed something.
     */
    public static function aRefundIsOwed(): self
    {
        return new self(
            'A refund for one of your cancelled orders has not arrived yet. '
            .'The account can be closed once it has.'
        );
    }

    /** An order somebody is still waiting on, as a buyer or as a shop. */
    public static function ordersAreOpen(): self
    {
        return new self(
            'You have orders that have not finished. '
            .'They have to be completed or called off before the account can be closed.'
        );
    }

    /**
     * The platform is still deciding something about them.
     *
     * Closing mid-dispute would take away the party whose case it is, and the
     * decision moves their money (ADR 0051).
     */
    public static function aDisputeIsOpen(): self
    {
        return new self(
            'A dispute about one of your orders is still being decided. '
            .'The account can be closed once it has been.'
        );
    }

    /**
     * A shop cannot be abandoned, and this action deliberately cannot close one.
     *
     * `sellers_suspension_is_whole` requires a member of staff against any
     * stopped shop, and there is none here - the owner is not staff, and writing
     * their own id into that column would record a lie. So a shop is refused
     * rather than quietly suspended, and closing one is its own chapter.
     */
    public static function aShopIsStillOpen(): self
    {
        return new self(
            'Your shop is still open. A shop cannot be closed from here yet, '
            .'so the account it belongs to cannot be either.'
        );
    }
}
