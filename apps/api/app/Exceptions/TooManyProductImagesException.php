<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The listing already has as many photographs as it may have.
 *
 * **409, not 422.** The file that was sent is perfectly valid - there is
 * nowhere to put it. That is the state of the listing rather than a fault in
 * the upload, and there is no field to show an error beside (ADR 0008).
 */
final class TooManyProductImagesException extends RuntimeException
{
    private function __construct(string $message, public readonly int $limit)
    {
        parent::__construct($message);
    }

    public static function limitOf(int $limit): self
    {
        return new self(
            sprintf('A listing can have %d images. Remove one before adding another.', $limit),
            $limit,
        );
    }
}
