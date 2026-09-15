<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the platform decided (ADR 0060).
 *
 * Every case is a decision a person took about a shop, and they come in pairs:
 * the sanction, and its reversal. Recording only the sanctions would leave a
 * record that says a shop was suspended and never says it was let back - which
 * is the more misleading of the two half-truths.
 *
 * **Hiding a review is deliberately not a case here.** It is a decision about a
 * buyer's words rather than about the shop, and a shop's record that counted it
 * would show strikes its own customers had earned.
 *
 * **Approving and rejecting an application are not here either.** Neither is
 * erased by anything - `reviewed_at` and `reviewed_by` survive a suspension and
 * survive being lifted - so a row here would duplicate a fact the shop already
 * carries. This table is for what would otherwise be lost.
 */
enum DecisionKind: string
{
    /** Stopped from trading (ADR 0052). */
    case ShopSuspended = 'shop_suspended';

    /** Let back, by staff or by an upheld appeal (ADR 0052, ADR 0059). */
    case ShopReinstated = 'shop_reinstated';

    /** A listing taken off sale by staff, upholding a report (ADR 0054). */
    case ListingRemoved = 'listing_removed';

    /** The removal lifted by an upheld appeal, leaving a draft (ADR 0059). */
    case ListingRestored = 'listing_restored';

    /** A dispute decided for the buyer: the order is cancelled and refunded. */
    case DisputeRefunded = 'dispute_refunded';

    /** A dispute decided for the shop: the order completes and money moves. */
    case DisputeReleased = 'dispute_released';

    /** An appeal the platform agreed with, which lifted the sanction. */
    case AppealUpheld = 'appeal_upheld';

    /** An appeal the platform did not agree with. The sanction stands. */
    case AppealDismissed = 'appeal_dismissed';

    /** What to call it, in the words staff read on the record. */
    public function label(): string
    {
        return match ($this) {
            self::ShopSuspended => 'Shop suspended',
            self::ShopReinstated => 'Shop reinstated',
            self::ListingRemoved => 'Listing taken down',
            self::ListingRestored => 'Listing restored',
            self::DisputeRefunded => 'Dispute refunded to the buyer',
            self::DisputeReleased => 'Dispute released to the shop',
            self::AppealUpheld => 'Appeal upheld',
            self::AppealDismissed => 'Appeal dismissed',
        };
    }

    /**
     * Whether this one counts against the shop.
     *
     * A record is read to weigh a shop, and half of these are the platform
     * deciding in its favour - a reinstatement, a restored listing, a dispute
     * released to it. Saying which is which here keeps the page from having to
     * work it out, and keeps "how many times has this shop been in trouble"
     * from counting the times it was cleared.
     */
    public function countsAgainstTheShop(): bool
    {
        return match ($this) {
            self::ShopSuspended, self::ListingRemoved, self::DisputeRefunded => true,
            self::ShopReinstated, self::ListingRestored, self::DisputeReleased,
            self::AppealUpheld, self::AppealDismissed => false,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
