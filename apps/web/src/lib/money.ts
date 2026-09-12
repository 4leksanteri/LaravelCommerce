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

  return formatter.format(amountMinor / 10 ** fractionDigits(currency));
}

/** How many minor-unit digits this currency has, according to Intl. */
function fractionDigits(currency: Currency): number {
  return formatterFor(currency).resolvedOptions().maximumFractionDigits ?? 2;
}

/**
 * A price a seller typed, as the integer minor units the API stores.
 *
 * **The one place the browser turns what somebody typed into money**, and it is
 * a price being set rather than a figure being computed - nothing here totals,
 * discounts or converts (ADR 0004, `apps/web/CLAUDE.md` section 8). A listing
 * form has no choice: `price_minor` is what the API takes, and nobody types
 * 2499 for 24.99.
 *
 * **Done on the string, never with a float.** `24.99 * 100` is 2498.9999... in
 * binary, and `Math.round` would hide that until the day it did not. The digits
 * after the separator are padded or refused as text, so what is sent is exactly
 * what was read.
 *
 * Returns null for anything that is not a plain amount: the field then says so,
 * rather than sending a figure nobody typed. Both separators are accepted,
 * because a keyboard in Helsinki writes 24,99 (ADR 0038).
 */
export function parseMoney(input: string, currency: Currency): number | null {
  const trimmed = input.trim().replace(",", ".");

  if (!/^\d+(\.\d*)?$/.test(trimmed)) {
    return null;
  }

  const digits = fractionDigits(currency);
  const [major, minor = ""] = trimmed.split(".");

  if (minor.length > digits) {
    return null;
  }

  const padded = minor.padEnd(digits, "0");
  const amount = Number(`${major}${padded}`);

  return Number.isSafeInteger(amount) ? amount : null;
}

/**
 * Minor units as the plain number a price field starts with: `2499` in EUR
 * becomes "24.99", and no currency symbol, because it goes in an input rather
 * than in a sentence.
 */
export function moneyInputValue(amountMinor: number, currency: Currency): string {
  const digits = fractionDigits(currency);

  if (digits === 0) {
    return String(amountMinor);
  }

  const negative = amountMinor < 0;
  const padded = String(Math.abs(amountMinor)).padStart(digits + 1, "0");
  const major = padded.slice(0, -digits);
  const minor = padded.slice(-digits);

  return `${negative ? "-" : ""}${major}.${minor}`;
}
