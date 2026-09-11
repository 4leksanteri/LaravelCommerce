// @vitest-environment node
import { describe, expect, it } from "vitest";

import type { Currency } from "@/lib/api/types";

import { formatMoney } from "./money";

// Intl separates a currency code from its amount with a no-break space. Written
// as an escape rather than typed, because the character itself is
// indistinguishable from a plain space in review (root CLAUDE.md section 15).
const NBSP = "\u00a0";

describe("formatMoney", () => {
  it.each<[number, Currency, string]>([
    [2899, "EUR", "\u20ac28.99"],
    [6500, "GBP", "\u00a365.00"],
    [499900, "SEK", `SEK${NBSP}4,999.00`],
    [95000, "DKK", `DKK${NBSP}950.00`],
    [0, "EUR", "\u20ac0.00"],
  ])("formats %i minor units of %s as %s", (amount, currency, expected) => {
    expect(formatMoney(amount, currency)).toBe(expected);
  });

  /**
   * The rule `App\Enums\Currency` states and this module exists to keep: the
   * divisor comes from the currency, not from a hundred. No shop can price in
   * yen yet, so the cast is the only way to ask - and a hard-coded `/ 100`
   * would print 500 yen as five.
   */
  it("asks the currency how many minor-unit digits it has", () => {
    const yen = "JPY" as Currency;

    expect(formatMoney(500, yen)).toBe(
      new Intl.NumberFormat("en-GB", { style: "currency", currency: "JPY" }).format(500),
    );
  });
});
