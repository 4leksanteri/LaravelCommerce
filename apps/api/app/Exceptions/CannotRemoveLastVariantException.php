<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Every product has at least one variant.
 *
 * That invariant is what lets everything else stop asking whether a price
 * exists: a product always has one, on its variant. Removing the last one
 * would leave a listing that cannot be bought and has no price, which is not a
 * state anything downstream is written to handle.
 *
 * The way to retire a product is to unpublish it, or to delete it.
 *
 * 409, not 422: the request named a real variant and the seller owns it. What
 * is wrong is what would be left behind.
 */
final class CannotRemoveLastVariantException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'A product must keep at least one variant. Unpublish or delete the product instead.',
        );
    }
}
