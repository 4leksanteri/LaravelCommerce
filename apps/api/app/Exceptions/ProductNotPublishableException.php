<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A product that cannot go on sale yet, because of the state of the shop
 * rather than because of who is asking.
 *
 * 409, for the reason ADR 0008 gives: the seller is entitled to publish their
 * own products, and what is in the way is a fact about the world. Answering
 * 403 would say they may not do something they may do.
 */
final class ProductNotPublishableException extends RuntimeException
{
    public static function shopNotApproved(): self
    {
        return new self(
            'This shop has not been approved yet, so its products cannot be published.',
        );
    }
}
