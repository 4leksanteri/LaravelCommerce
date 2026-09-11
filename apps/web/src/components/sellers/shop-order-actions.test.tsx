import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { SellerOrder } from "@/lib/api/types";

import { ShopOrderActions } from "./shop-order-actions";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const pending: SellerOrder = {
  reference: "K7M2QXV9RT",
  status: "pending",
  buyer_name: "Aino Virtanen",
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
  can_accept: true,
  can_ship: false,
  can_cancel: true,
};

const accepted: SellerOrder = {
  ...pending,
  status: "accepted",
  accepted_at: "2026-03-01T12:00:00+00:00",
  can_accept: false,
  can_ship: true,
};

const completed: SellerOrder = {
  ...accepted,
  status: "completed",
  can_ship: false,
  can_cancel: false,
};

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("ShopOrderActions", () => {
  it.each<[string, SellerOrder, string[], string[]]>([
    ["waiting to be accepted", pending, ["Accept order", "Cancel order"], ["Mark as sent"]],
    ["accepted", accepted, ["Mark as sent", "Cancel order"], ["Accept order"]],
  ])("draws what the API allows for an order %s", (_, order, drawn, absent) => {
    render(<ShopOrderActions order={order} />);

    for (const name of drawn) {
      expect(screen.getByRole("button", { name })).toBeVisible();
    }
    for (const name of absent) {
      expect(screen.queryByRole("button", { name })).not.toBeInTheDocument();
    }
  });

  it("draws nothing for an order nobody can move any more", () => {
    render(<ShopOrderActions order={completed} />);

    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  /** The ordinary step, taken on one click, and the page redrawn from the API. */
  it("accepts on one click", async () => {
    request.mockResolvedValue({ data: accepted });
    const user = userEvent.setup();

    render(<ShopOrderActions order={pending} />);
    await user.click(screen.getByRole("button", { name: "Accept order" }));

    expect(request).toHaveBeenCalledWith("/seller/orders/K7M2QXV9RT/acceptance", {
      method: "POST",
    });
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("asks for the reason before cancelling, and sends it", async () => {
    request.mockResolvedValue({ data: { ...pending, status: "cancelled" } });
    const user = userEvent.setup();

    render(<ShopOrderActions order={pending} />);
    await user.click(screen.getByRole("button", { name: "Cancel order" }));

    expect(request).not.toHaveBeenCalled();
    expect(screen.getByLabelText("Why are you cancelling?")).toHaveFocus();

    await user.type(screen.getByLabelText("Why are you cancelling?"), "The last one sold.");
    await user.click(screen.getByRole("button", { name: "Cancel the order" }));

    expect(request).toHaveBeenCalledWith(
      "/seller/orders/K7M2QXV9RT/cancellation",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ reason: "The last one sold." }),
      }),
    );
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("puts the API's refusal beside the reason", async () => {
    const message = "The reason field is required.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { reason: [message] } }));
    const user = userEvent.setup();

    render(<ShopOrderActions order={pending} />);
    await user.click(screen.getByRole("button", { name: "Cancel order" }));
    await user.click(screen.getByRole("button", { name: "Cancel the order" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.getByLabelText("Why are you cancelling?")).toHaveAttribute(
      "aria-invalid",
      "true",
    );
  });

  it("sends nothing when the shop keeps the order", async () => {
    const user = userEvent.setup();

    render(<ShopOrderActions order={pending} />);
    await user.click(screen.getByRole("button", { name: "Cancel order" }));
    await user.click(screen.getByRole("button", { name: "Keep it" }));

    expect(request).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Accept order" })).toBeVisible();
  });

  /** The buyer cancelled while the page was open. */
  it("shows the API's reason when the order has moved on, and redraws the page", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "The order has moved on.", status: "cancelled" }),
    );
    const user = userEvent.setup();

    render(<ShopOrderActions order={pending} />);
    await user.click(screen.getByRole("button", { name: "Accept order" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("The order has moved on.");
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("sends somebody whose session ended to sign in, and back to the order", async () => {
    request.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));
    const user = userEvent.setup();

    render(<ShopOrderActions order={accepted} />);
    await user.click(screen.getByRole("button", { name: "Mark as sent" }));

    expect(router.push).toHaveBeenCalledWith("/login?next=%2Fseller%2Forders%2FK7M2QXV9RT");
  });
});
