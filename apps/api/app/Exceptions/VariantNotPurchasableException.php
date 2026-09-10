<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Somebody asked for more of something than can be had, or for something that
 * has stopped being for sale while it sat in their cart.
 *
 * **409, and deliberately not 422.** `"quantity": 50` is a perfectly valid
 * integer; what is wrong is that there are three left. That is the state of the
 * world rather than the shape of the request, which is the line ADR 0008 draws
 * between the two statuses - and it is the difference between a frontend
 * showing "check this field" and one offering "reduce to 3".
 *
 * It is not a 403 either. The caller is entitled to buy the thing.
 *
 * `$available` is how many can actually be had, and is null when the reason is
 * not stock. It is published in the response body so the frontend can offer the
 * next step rather than making the shopper find the number by trying.
 */
final class VariantNotPurchasableException extends RuntimeException
{
    private function __construct(string $message, public readonly ?int $available)
    {
        parent::__construct($message);
    }

    public static function onlyAvailable(int $available): self
    {
        if ($available === 0) {
            return new self('This is sold out.', 0);
        }

        return new self(
            sprintf('Only %d of these are left.', $available),
            $available,
        );
    }

    public static function noLongerForSale(): self
    {
        return new self('This is no longer for sale.', null);
    }
}
