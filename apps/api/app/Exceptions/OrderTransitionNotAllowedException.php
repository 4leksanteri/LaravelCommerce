<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\OrderParty;
use App\Enums\OrderStatus;
use RuntimeException;

/**
 * The order is not in a state that allows what was asked.
 *
 * **409, not 403.** The caller is a party to this order and is entitled to act
 * on it; what is in the way is where the order has got to. A buyer trying to
 * cancel something already accepted has not done anything they may not do -
 * they are late (ADR 0008).
 *
 * `status` is published alongside the message so a client can re-render the
 * order without fetching it again, which is usually why it got here: it drew a
 * button from state that had since moved.
 */
final class OrderTransitionNotAllowedException extends RuntimeException
{
    private function __construct(string $message, public readonly OrderStatus $status)
    {
        parent::__construct($message);
    }

    public static function cannotCancel(OrderStatus $from, OrderParty $party): self
    {
        $message = match (true) {
            $from === OrderStatus::Accepted && $party === OrderParty::Buyer => 'The seller has already accepted this order, so only they can cancel it now.',
            $from === OrderStatus::Shipped => 'This order has already been shipped, so it cannot be cancelled.',
            $from === OrderStatus::Completed => 'This order is complete.',
            $from === OrderStatus::Cancelled => 'This order has already been cancelled.',
            default => 'This order cannot be cancelled.',
        };

        return new self($message, $from);
    }

    /**
     * Accepting something nobody has paid for.
     *
     * Not reachable from a shop's own queue, which never shows an unpaid order
     * (ADR 0042) - this is for a page that was open when the payment failed, or
     * anything calling the endpoint directly. A 409 rather than a 404: the
     * order is theirs and the request was valid, and what is in the way is that
     * the money has not arrived.
     */
    public static function notPaid(OrderStatus $from): self
    {
        return new self('Nobody has paid for this order yet.', $from);
    }

    public static function cannotAccept(OrderStatus $from): self
    {
        return new self(
            $from === OrderStatus::Cancelled
                ? 'This order has been cancelled.'
                : 'This order has already been accepted.',
            $from,
        );
    }

    public static function cannotShip(OrderStatus $from): self
    {
        $message = match ($from) {
            OrderStatus::Pending => 'Accept this order before shipping it.',
            OrderStatus::Cancelled => 'This order has been cancelled.',
            default => 'This order has already been shipped.',
        };

        return new self($message, $from);
    }

    public static function cannotExtend(OrderStatus $from): self
    {
        $message = match ($from) {
            OrderStatus::Completed => 'This order is already complete.',
            OrderStatus::Cancelled => 'This order has been cancelled.',
            default => 'This order has not been shipped yet, so there is nothing to wait for.',
        };

        return new self($message, $from);
    }

    /**
     * The cap, reached. What a buyer needs at this point is a dispute, and
     * there are none - so the message says what will happen rather than
     * offering something that does not exist.
     */
    public static function noExtensionsLeft(OrderStatus $from): self
    {
        return new self(
            'This order has been extended as far as it can be, and will complete on its own.',
            $from,
        );
    }

    public static function cannotComplete(OrderStatus $from): self
    {
        $message = match ($from) {
            OrderStatus::Cancelled => 'This order has been cancelled.',
            OrderStatus::Completed => 'This order is already complete.',
            default => 'This order has not been shipped yet.',
        };

        return new self($message, $from);
    }
}
