import type { Currency } from "@/lib/api/types";

/**
 * Turning an integer number of minor units into something a person reads.
 *
 * **Formatting only.** Nothing here adds, multiplies, discounts or totals, and
 * nothing that comes out of it is ever sent back to the API. The figure a buyer
 * is charged is computed by the API (ADR 0004, `apps/web/CLAUDE.md` section 8);
 * this draws it.
 *
 * **The exponent is asked for, not assumed.** Every currency a shop can trade in
 * today has two minor-unit digits, and `App\Enums\Currency` says in as many
 * words that no code should rely on that: JPY and ISK have none. So the divisor
 * comes from `Intl`, which knows ISO 4217, rather than being a 100 that would be
 * silently wrong by a factor of a hundred the day a zero-digit currency is
 * added.
 *
 * The division is the one arithmetic operation `apps/web/CLAUDE.md` allows on
 * money: converting minor units for a formatter, on a value that is displayed
 * and never sent back. `Intl` rounds to the currency's own digits, so the
 * binary representation of 28.99 never reaches the page.
 *
 * **One locale, fixed.** Server and browser must render the same string or
 * React reports a hydration mismatch, and the server's default locale is
 * whatever the container happens to have. When the interface is translated,
 * the locale becomes a parameter that both sides agree on - not a default
 * either side guesses.
 */
const LOCALE = "en-GB";

const formatters = new Map<Currency, Intl.NumberFormat>();

function formatterFor(currency: Currency): Intl.NumberFormat {
  let formatter = formatters.get(currency);

  if (!formatter) {
    formatter = new Intl.NumberFormat(LOCALE, { style: "currency", currency });
    formatters.set(currency, formatter);
  }

  return formatter;
}

export function formatMoney(amountMinor: number, currency: Currency): string {
  const formatter = formatterFor(currency);
  const digits = formatter.resolvedOptions().maximumFractionDigits ?? 2;

  return formatter.format(amountMinor / 10 ** digits);
}
