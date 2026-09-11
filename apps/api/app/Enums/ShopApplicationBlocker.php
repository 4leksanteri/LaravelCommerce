<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What stands between an account and applying to open a shop.
 *
 * The answer rather than the inputs, as CheckoutBlocker is (ADR 0030). The
 * page that draws the application needs to know whether it can, and a browser
 * handed `email_verified_at` and the shop's status would be re-deriving two
 * rules this application owns: `verified` on the application route, and
 * ApplyToSell's one application at a time.
 *
 * One reason, in the order the application itself refuses: the middleware
 * runs before the action. A rejected applicant has none. They may apply again,
 * which is what a rejection returning to pending means (ADR 0007).
 */
enum ShopApplicationBlocker: string
{
    /** Applying needs an address somebody has shown they can read. */
    case UnverifiedEmail = 'unverified_email';

    /** An application is already waiting for staff. */
    case AwaitingReview = 'awaiting_review';

    /** The account already has an approved shop, and one is all it may have. */
    case AlreadyOpen = 'already_open';
}
