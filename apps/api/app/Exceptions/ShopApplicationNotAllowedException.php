<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * An application that cannot be made, because of the state of the account
 * rather than because of who is asking.
 *
 * **This is not an authorization failure**, and the distinction is the whole
 * reason it exists rather than living in `SellerPolicy`. The applicant is
 * perfectly entitled to apply; they have already applied, or they already have
 * a shop. Answering 403 would tell them they are not allowed to do something
 * they are allowed to do, and answering 422 would blame the fields they sent,
 * which were fine.
 *
 * It renders as **409 Conflict**, registered once in bootstrap/app.php.
 */
final class ShopApplicationNotAllowedException extends RuntimeException
{
    public static function alreadyPending(): self
    {
        return new self('An application for this account is already awaiting review.');
    }

    public static function alreadyApproved(): self
    {
        return new self('This account already has an approved shop.');
    }
}
