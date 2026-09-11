import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Order } from "@/lib/api/types";

import { OrderTimeline } from "./order-timeline";

const placed: Order = {
  reference: "K7M2QXV9RT",
  checkout_reference: "C4XJ8T2MNP",
  status: "pending",
  shop_slug: "second-hand-time",
  shop_name: "Second Hand Time",
  currency: "EUR",
  total_minor: 95000,
  shipping_address: null,
  item_count: 1,
  items: [],
  placed_at: "2026-03-01T10:00:00+00:00",
  accepted_at: null,
  shipped_at: null,
  completed_at: null,
  cancelled_at: null,
  cancelled_by: null,
  cancellation_reason: null,
  completed_by: null,
  auto_complete_at: null,
  completion_extensions_left: 2,
  can_cancel: true,
  can_complete: false,
  can_extend_completion: false,
};

const cancelled = (by: Order["cancelled_by"], reason: string | null = null): Order => ({
  ...placed,
  status: "cancelled",
  cancelled_at: "2026-03-02T10:00:00+00:00",
  cancelled_by: by,
  cancellation_reason: reason,
  can_cancel: false,
});

const completed = (by: Order["completed_by"]): Order => ({
  ...placed,
  status: "completed",
  accepted_at: "2026-03-01T12:00:00+00:00",
  shipped_at: "2026-03-02T09:00:00+00:00",
  completed_at: "2026-03-05T09:00:00+00:00",
  completed_by: by,
  auto_complete_at: "2026-03-16T09:00:00+00:00",
  can_cancel: false,
});

describe("OrderTimeline", () => {
  it("says what the order is waiting for", () => {
    render(<OrderTimeline order={placed} />);

    expect(screen.getByText("Waiting for Second Hand Time to accept it.")).toBeVisible();
  });

  /**
   * Who called it off is recorded (ADR 0035), so the timeline says - and the
   * shop's reason is the buyer's to read.
   */
  it.each([
    [cancelled("buyer"), "You cancelled it."],
    [
      cancelled("seller", "The last one sold in the shop."),
      "Second Hand Time cancelled it. Their reason: The last one sold in the shop.",
    ],
    [cancelled("deadline"), "Second Hand Time did not accept it in time, so it was cancelled."],
    [cancelled(null), "Nothing more will happen to this order."],
  ])("says who cancelled it", (order, note) => {
    render(<OrderTimeline order={order} />);

    expect(screen.getByText(note)).toBeVisible();
  });

  it.each([
    [completed("buyer"), "You confirmed it arrived."],
    [completed("deadline"), "It completed on its own when its deadline passed."],
  ])("says who completed it", (order, note) => {
    render(<OrderTimeline order={order} />);

    expect(screen.getByText(note)).toBeVisible();
  });
});
