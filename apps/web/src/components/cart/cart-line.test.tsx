import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { CartItem } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";

import { CartLine } from "./cart-line";

vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }) }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const gbp = (minor: number) => formatMoney(minor, "GBP");

function line(overrides: Partial<CartItem> = {}): CartItem {
  return {
    id: 7,
    quantity: 2,
    product_name: "Boss DS-1 distortion pedal",
    variant_name: "Default",
    product_slug: "boss-ds-1-distortion-pedal",
    variant_id: 31,
    unit_price_minor: 3500,
    added_price_minor: 3500,
    price_changed: false,
    line_total_minor: 7000,
    availability: "available",
    available_quantity: null,
    ...overrides,
  };
}

function show(item: CartItem) {
  render(
    <ul>
      <CartLine item={item} shopSlug="fret-and-valve" currency="GBP" />
    </ul>,
  );
}

describe("CartLine", () => {
  /**
   * The line total is given here as a figure that is deliberately NOT the unit
   * price times the quantity. If the card worked it out itself, this would show
   * the product; it has to show the API's number.
   */
  it("shows the API's line total rather than multiplying price by quantity", () => {
    show(line({ line_total_minor: 6999 }));

    expect(screen.getByText(gbp(6999))).toBeVisible();
    expect(screen.queryByText(gbp(7000))).toBeNull();
  });

  it("links the listing while there is one, and names it without a link once it is gone", () => {
    const { unmount } = render(
      <ul>
        <CartLine item={line()} shopSlug="fret-and-valve" currency="GBP" />
      </ul>,
    );

    expect(screen.getByRole("link", { name: "Boss DS-1 distortion pedal" })).toHaveAttribute(
      "href",
      "/shops/fret-and-valve/products/boss-ds-1-distortion-pedal",
    );

    unmount();
    show(line({ product_slug: null }));

    expect(screen.getByText("Boss DS-1 distortion pedal")).toBeVisible();
    expect(screen.queryByRole("link", { name: "Boss DS-1 distortion pedal" })).toBeNull();
  });

  it("says the price moved, and what it was, without saying which way", () => {
    show(line({ price_changed: true, added_price_minor: 3000 }));

    expect(screen.getByText(/The price has changed since you added it/)).toHaveTextContent(
      gbp(3000),
    );
  });

  it.each<[CartItem["availability"], RegExp]>([
    ["no_longer_for_sale", /No longer for sale/],
    ["out_of_stock", /Out of stock/],
  ])("says why a line cannot be bought: %s", (availability, message) => {
    show(line({ availability }));

    expect(screen.getByText(message)).toBeVisible();
  });

  it("says how many are left when there are fewer than asked for, as the API counted", () => {
    show(line({ availability: "insufficient_stock", available_quantity: 1, quantity: 3 }));

    expect(screen.getByText(/Only 1 left/)).toBeVisible();
  });

  it("says nothing about availability when the line can be bought", () => {
    show(line());

    expect(screen.queryByText(/left|stock|for sale/)).toBeNull();
  });
});
