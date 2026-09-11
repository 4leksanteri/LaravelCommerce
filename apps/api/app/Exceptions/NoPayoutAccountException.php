<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Details sent for a payout account the shop has not opened.
 *
 * 409 rather than 404: the endpoint is the shop's own and exists, and what is
 * missing is a step the owner has not taken yet.
 */
final class NoPayoutAccountException extends RuntimeException
{
    public static function forShop(): self
    {
        return new self('This shop has no payout account yet. Open one first.');
    }
}
