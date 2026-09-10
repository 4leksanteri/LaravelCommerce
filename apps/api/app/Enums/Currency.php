<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The currencies a shop may trade in. ISO 4217.
 *
 * A seller chooses one when applying and it does not change afterwards
 * (ADR 0007). Everything the shop does - prices, orders, refunds, payouts - is
 * in it, and amounts in different currencies are never added together
 * (ADR 0004).
 *
 * The set is small on purpose. Each currency added is a payout arrangement, a
 * rounding rule and a set of test expectations, so they are added when a
 * seller needs one rather than because the code could hold them.
 *
 * **Every case here happens to have two minor-unit digits, and no code should
 * assume that.** JPY and ISK have none; adding either means every place that
 * turns minor units into something a person reads has to ask the currency
 * rather than divide by 100. There is no `minorUnitDigits()` method yet
 * because there is no money in the schema yet - it arrives with the first
 * price, in the same change.
 */
enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';
    case SEK = 'SEK';
    case NOK = 'NOK';
    case DKK = 'DKK';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
