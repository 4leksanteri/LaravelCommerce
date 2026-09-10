<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Somebody tried to take the category off a listing that is on sale.
 *
 * The database refuses it - `products_published_category_check` says a
 * published row has a category - and without this the seller got a constraint
 * violation rendered as a 500. A rule the schema enforces still needs somebody
 * to translate it into an answer.
 *
 * **409**: they are entitled to edit their own listing and `null` is a valid
 * value for that column, which is exactly why a draft may have it. What is in
 * the way is that this one is published (ADR 0008).
 */
final class PublishedProductNeedsCategoryException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'A published listing needs a category. Choose a different one, or unpublish it first.',
        );
    }
}
