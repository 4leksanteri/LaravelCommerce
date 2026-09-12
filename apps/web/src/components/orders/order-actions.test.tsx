import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Order } from "@/lib/api/types";

import { OrderActions } from "./order-actions";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const shipped: Order = {
  reference: "K7M2QXV9RT",
  checkout_reference: "C4XJ8T2MNP",
  status: "shipped",
  shop_slug: "second-hand-time",
  shop_name: "Second Hand Time",
  currency: "EUR",
  total_minor: 95000,
  shipping_address: null,
  item_count: 1,
  items: [],
  placed_at: "2026-03-01T10:00:00+00:00",
  accepted_at: "2026-03-01T12:00:00+00:00",
  shipped_at: "2026-03-02T09:00:00+00:00",
  completed_at: null,
  cancelled_at: null,
  cancelled_by: null,
  cancellation_reason: null,
  completed_by: null,
  payment_status: "succeeded",
  paid_at: "2026-03-01T10:00:05+00:00",
  refunded_at: null,
  can_pay: false,
  auto_complete_at: "2026-03-16T09:00:00+00:00",
  completion_extensions_left: 2,
  can_cancel: false,
  can_complete: true,
  can_extend_completion: true,
};

const pending: Order = {
  ...shipped,
  status: "pending",
  accepted_at: null,
  shipped_at: null,
  auto_complete_at: null,
  can_cancel: true,
  can_complete: false,
  can_extend_completion: false,
};

const completed: Order = {
  ...shipped,
  status: "completed",
  completed_at: "2026-03-05T09:00:00+00:00",
  can_complete: false,
  can_extend_completion: false,
};

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("OrderActions", () => {
  /**
   * A shipped order: the buyer may confirm it or ask for more time, and may
   * not cancel it - that is the shop's alone once it has accepted. The
   * component is told all three and draws exactly those.
   */
  it("draws what the API allows and nothing else", () => {
    render(<OrderActions order={shipped} />);

    expect(screen.getByRole("button", { name: "Confirm it arrived" })).toBeVisible();
    expect(screen.getByRole("button", { name: "It has not arrived yet" })).toBeVisible();
    expect(screen.getByText("2 extensions left")).toBeVisible();
    expect(screen.queryByRole("button", { name: "Cancel order" })).not.toBeInTheDocument();
  });

  it("draws nothing for an order nobody can move any more", () => {
    render(<OrderActions order={completed} />);

    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("asks before confirming arrival, and sends it only on a yes", async () => {
    request.mockResolvedValue({ data: { ...shipped, status: "completed" } });
    const user = userEvent.setup();

    render(<OrderActions order={shipped} />);
    await user.click(screen.getByRole("button", { name: "Confirm it arrived" }));

    expect(request).not.toHaveBeenCalled();
    // The question replaces the button, so the focus goes to the answer
    // rather than being dropped on the page.
    expect(screen.getByRole("button", { name: "Yes, it arrived" })).toHaveFocus();

    await user.click(screen.getByRole("button", { name: "Yes, it arrived" }));

    expect(request).toHaveBeenCalledWith("/orders/K7M2QXV9RT/completion", { method: "POST" });
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("sends nothing when the answer is no", async () => {
    const user = userEvent.setup();

    render(<OrderActions order={pending} />);
    await user.click(screen.getByRole("button", { name: "Cancel order" }));
    await user.click(screen.getByRole("button", { name: "Keep it" }));

    expect(request).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Cancel order" })).toBeVisible();
  });

  it("cancels an order the shop has not accepted yet", async () => {
    request.mockResolvedValue({ data: { ...pending, status: "cancelled", can_cancel: false } });
    const user = userEvent.setup();

    render(<OrderActions order={pending} />);
    await user.click(screen.getByRole("button", { name: "Cancel order" }));
    await user.click(screen.getByRole("button", { name: "Yes, cancel it" }));

    expect(request).toHaveBeenCalledWith("/orders/K7M2QXV9RT/cancellation", { method: "POST" });
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  /**
   * How long an extension is belongs to the API. The date comes from its
   * answer rather than from adding a week here.
   */
  it("says where the deadline moved to, in the API's date", async () => {
    request.mockResolvedValue({
      data: {
        ...shipped,
        auto_complete_at: "2026-03-23T09:00:00+00:00",
        completion_extensions_left: 1,
      },
    });
    const user = userEvent.setup();

    render(<OrderActions order={shipped} />);
    await user.click(screen.getByRole("button", { name: "It has not arrived yet" }));

    expect(request).toHaveBeenCalledWith("/orders/K7M2QXV9RT/completion-extension", {
      method: "POST",
    });
    expect(
      await screen.findByText("Given more time: it now completes on its own on 23 Mar 2026."),
    ).toBeVisible();
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  /**
   * The order moved while the page was open - the deadline passed, say. The
   * API's sentence is the reason, and the page is drawn again from the order
   * as it now is.
   */
  it("shows the API's reason when the order has moved on, and redraws the page", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "The order has moved on.", status: "completed" }),
    );
    const user = userEvent.setup();

    render(<OrderActions order={shipped} />);
    await user.click(screen.getByRole("button", { name: "Confirm it arrived" }));
    await user.click(screen.getByRole("button", { name: "Yes, it arrived" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("The order has moved on.");
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("sends somebody whose session ended to sign in, and back to the order", async () => {
    request.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));
    const user = userEvent.setup();

    render(<OrderActions order={shipped} />);
    await user.click(screen.getByRole("button", { name: "It has not arrived yet" }));

    // Back to the order's page, not to the API's address for it.
    expect(router.push).toHaveBeenCalledWith("/login?next=%2Faccount%2Forders%2FK7M2QXV9RT");
  });
});
