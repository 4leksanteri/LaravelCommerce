import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { Order } from "@/lib/api/types";
import type { OrderReader } from "@/lib/orders/status";

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

const sent: Order = {
  ...placed,
  status: "shipped",
  accepted_at: "2026-03-01T12:00:00+00:00",
  shipped_at: "2026-03-02T09:00:00+00:00",
  auto_complete_at: "2026-03-16T09:00:00+00:00",
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
  ...sent,
  status: "completed",
  completed_at: "2026-03-05T09:00:00+00:00",
  completed_by: by,
});

// Each side is shown the other's name: the shop's to a buyer, the buyer's to a shop.
function show(order: Order, reader: OrderReader) {
  render(
    <OrderTimeline
      order={order}
      reader={reader}
      counterpart={reader === "buyer" ? "Second Hand Time" : "Aino Virtanen"}
    />,
  );
}

describe("OrderTimeline", () => {
  /** The same order, waiting on the shop, read by each side (ADR 0036). */
  it.each<[OrderReader, string]>([
    ["buyer", "Waiting for Second Hand Time to accept it."],
    ["shop", "Waiting for you to accept it."],
  ])("says what the order is waiting for, to the %s", (reader, note) => {
    show(placed, reader);

    expect(screen.getByText(note)).toBeVisible();
  });

  it.each<[OrderReader, string]>([
    [
      "buyer",
      "Confirm it arrived once you have checked it over. If you do not, it completes on its own on 16 Mar 2026.",
    ],
    [
      "shop",
      "Waiting for Aino Virtanen to confirm it arrived. If they do not, it completes on its own on 16 Mar 2026.",
    ],
  ])("says when a sent order completes on its own, to the %s", (reader, note) => {
    show(sent, reader);

    expect(screen.getByText(note)).toBeVisible();
  });

  /** Who called it off is recorded (ADR 0035), and each side is told in its own words. */
  it.each<[Order, OrderReader, string]>([
    [cancelled("buyer"), "buyer", "You cancelled it."],
    [cancelled("buyer"), "shop", "Aino Virtanen cancelled it."],
    [
      cancelled("seller", "The last one sold in the shop."),
      "buyer",
      "Second Hand Time cancelled it. Their reason: The last one sold in the shop.",
    ],
    [
      cancelled("seller", "The last one sold in the shop."),
      "shop",
      "You cancelled it. Your reason: The last one sold in the shop.",
    ],
    [
      cancelled("deadline"),
      "buyer",
      "Second Hand Time did not accept it in time, so it was cancelled.",
    ],
    [cancelled("deadline"), "shop", "It was not accepted in time, so it was cancelled."],
    [cancelled(null), "buyer", "Nothing more will happen to this order."],
  ])("says who cancelled it", (order, reader, note) => {
    show(order, reader);

    expect(screen.getByText(note)).toBeVisible();
  });

  it.each<[Order, OrderReader, string]>([
    [completed("buyer"), "buyer", "You confirmed it arrived."],
    [completed("buyer"), "shop", "Aino Virtanen confirmed it arrived."],
    [completed("deadline"), "shop", "It completed on its own when its deadline passed."],
  ])("says who completed it", (order, reader, note) => {
    show(order, reader);

    expect(screen.getByText(note)).toBeVisible();
  });
});
