<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a seller may open a payout account. ISO 3166-1 alpha-2.
 *
 * Two conditions, and a country is here only when both hold:
 *
 *   - **Stripe lets this platform hold accounts there.** The platform is in
 *     Finland (STRIPE_PLATFORM_COUNTRY), and a European platform's connected
 *     accounts are European. Anywhere else would need Stripe's cross-border
 *     payouts, which this project has not looked into.
 *   - **Its own currency is one a shop may trade in** (Currency): the euro
 *     area, and Sweden, Norway, Denmark and the United Kingdom beside it.
 *
 * So there is no US, although a shop may price in dollars. A seller in Ireland
 * trading in USD is somebody this platform can pay; one based in the US is not,
 * yet. Adding a country is a question for Stripe first and this file second.
 *
 * Asked once, when the account is opened: Stripe does not move an account from
 * one country to another.
 */
enum PayoutCountry: string
{
    // The euro area, as of Bulgaria joining it on 1 January 2026.
    case AT = 'AT';
    case BE = 'BE';
    case BG = 'BG';
    case CY = 'CY';
    case DE = 'DE';
    case EE = 'EE';
    case ES = 'ES';
    case FI = 'FI';
    case FR = 'FR';
    case GR = 'GR';
    case HR = 'HR';
    case IE = 'IE';
    case IT = 'IT';
    case LT = 'LT';
    case LU = 'LU';
    case LV = 'LV';
    case MT = 'MT';
    case NL = 'NL';
    case PT = 'PT';
    case SI = 'SI';
    case SK = 'SK';

    // Each with a currency of its own that a shop may trade in.
    case DK = 'DK';
    case GB = 'GB';
    case NO = 'NO';
    case SE = 'SE';
}
