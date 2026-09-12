// @vitest-environment node
import { describe, expect, it } from "vitest";

import type { Currency } from "@/lib/api/types";

import { formatMoney, moneyInputValue, parseMoney } from "./money";

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

/**
 * A price a seller typed, on its way to the API as `price_minor` (ADR 0038).
 *
 * The float this avoids is the whole point: `24.99 * 100` is 2498.9999... in
 * binary, so the conversion is done on the string.
 */
describe("parseMoney", () => {
  it.each<[string, Currency, number]>([
    ["24.99", "EUR", 2499],
    ["24,99", "EUR", 2499],
    ["0.05", "EUR", 5],
    ["7", "EUR", 700],
    ["7.5", "EUR", 750],
    ["  12.30  ", "GBP", 1230],
    ["0", "EUR", 0],
    ["9999.99", "SEK", 999999],
  ])("reads %s as minor units", (typed, currency, expected) => {
    expect(parseMoney(typed, currency)).toBe(expected);
  });

  it.each<[string]>([
    [""],
    ["  "],
    ["24.999"],
    ["-5"],
    ["1.2.3"],
    ["12e3"],
    ["free"],
    ["\u20ac24.99"],
  ])("refuses %s rather than sending a figure nobody typed", (typed) => {
    expect(parseMoney(typed, "EUR")).toBeNull();
  });

  /** A currency with no minor units takes no decimals at all. */
  it("follows the currency's own digits", () => {
    const yen = "JPY" as Currency;

    expect(parseMoney("500", yen)).toBe(500);
    expect(parseMoney("500.5", yen)).toBeNull();
  });
});

describe("moneyInputValue", () => {
  it.each<[number, Currency, string]>([
    [2499, "EUR", "24.99"],
    [5, "EUR", "0.05"],
    [0, "EUR", "0.00"],
    [700, "GBP", "7.00"],
    [999999, "SEK", "9999.99"],
  ])("writes %i as %s for a price field", (minor, currency, expected) => {
    expect(moneyInputValue(minor, currency)).toBe(expected);
  });

  /** What comes out goes back in unchanged, which is what an edit form needs. */
  it.each<[number]>([[0], [5], [99], [2499], [123456]])("round-trips %i", (minor) => {
    expect(parseMoney(moneyInputValue(minor, "EUR"), "EUR")).toBe(minor);
  });
});
