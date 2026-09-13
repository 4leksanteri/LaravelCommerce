import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Transfer } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";

import { TransferList } from "./transfer-list";

/**
 * The payouts list (ADR 0043).
 *
 * Covered here rather than end to end because a transfer needs a verified
 * Stripe connected account, and the suite deliberately never opens one - so the
 * demo shop can never have a payout, and Playwright can only ever see the empty
 * state.
 *
 * The expectations are built with `formatMoney` rather than typed out: a
 * formatted price carries a currency symbol and a no-break space, and writing
 * either into a test file puts a character in source that the charset check
 * refuses (`apps/web/CLAUDE.md` section 12a).
 */
const paid: Transfer = {
  order_reference: "K7M2QXV9RT",
  currency: "EUR",
  charged_minor: 95000,
  platform_fee_minor: 4750,
  amount_minor: 90250,
  transferred_at: "2026-03-10T09:00:00+00:00",
};

describe("TransferList", () => {
  it("shows what arrived, what was charged and what was kept", () => {
    render(<TransferList transfers={[paid]} />);

    expect(screen.getByText(formatMoney(90250, "EUR"))).toBeInTheDocument();
    expect(
      screen.getByText(new RegExp(`${escapeForRegExp(formatMoney(95000, "EUR"))} charged`)),
    ).toBeInTheDocument();
    expect(
      screen.getByText(new RegExp(`${escapeForRegExp(formatMoney(4750, "EUR"))} fee`)),
    ).toBeInTheDocument();
  });

  it("links each payout to the order it came from", () => {
    render(<TransferList transfers={[paid]} />);

    expect(screen.getByRole("link", { name: /K7M2QXV9RT/ })).toHaveAttribute(
      "href",
      "/seller/orders/K7M2QXV9RT",
    );
  });

  it("says when it was sent", () => {
    render(<TransferList transfers={[paid]} />);

    expect(screen.getByText("Sent 10 Mar 2026")).toBeInTheDocument();
  });

  /** A shop with no payouts yet is told what would put one here. */
  it("explains the empty state rather than showing an empty list", () => {
    render(<TransferList transfers={[]} />);

    expect(screen.queryByRole("list")).not.toBeInTheDocument();
    expect(screen.getByText(/Nothing has been paid out yet/)).toBeInTheDocument();
  });
});

function escapeForRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}
