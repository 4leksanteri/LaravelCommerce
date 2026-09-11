<?php

declare(strict_types=1);

namespace App\Enums;

use NumberFormatter;

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
 * assume that.** JPY and ISK have none, which is why `format()` asks ICU how
 * many digits a currency has rather than dividing by 100.
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
     * An amount in this currency, as a person reads it.
     *
     * For mail, which is the one place the API writes money as text; every page
     * formats it in the browser. The locale is the web application's, en-GB,
     * so a mail and the page it links to write the same sum the same way
     * (ADR 0035). How many minor units make a major one is asked of ICU, the
     * same question `formatMoney` asks, and the division is for display only.
     */
    public function format(int $minor): string
    {
        $formatter = new NumberFormatter('en_GB', NumberFormatter::CURRENCY);
        $formatter->setTextAttribute(NumberFormatter::CURRENCY_CODE, $this->value);

        $digits = (int) $formatter->getAttribute(NumberFormatter::FRACTION_DIGITS);

        return (string) $formatter->formatCurrency($minor / (10 ** $digits), $this->value);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
