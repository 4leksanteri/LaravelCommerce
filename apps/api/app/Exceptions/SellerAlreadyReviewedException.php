<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\SellerStatus;
use RuntimeException;

/**
 * Two reviewers reached the same application, and one of them lost.
 *
 * A domain exception rather than a `false` return, because the caller has to
 * distinguish this from every other reason a decision might not have been
 * recorded, and because the status that won is part of the answer.
 *
 * It is translated to 409 Conflict at the HTTP boundary - not 403, which would
 * say the reviewer was not allowed, and not 422, which would say they sent
 * something wrong. Neither is true: they were allowed, they sent a valid
 * decision, and the world moved underneath them.
 */
final class SellerAlreadyReviewedException extends RuntimeException
{
    public function __construct(public readonly SellerStatus $status)
    {
        parent::__construct("This application has already been {$status->value}.");
    }
}
