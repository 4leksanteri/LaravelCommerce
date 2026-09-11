import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { CartItem } from "@/lib/api/types";

import { CartLineControls } from "./cart-line-controls";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

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

function sent(call = 0): { path: string; method: string; body: unknown } {
  const [path, init] = request.mock.calls[call] as [string, RequestInit];

  return {
    path,
    method: String(init.method),
    body: typeof init.body === "string" ? JSON.parse(init.body) : undefined,
  };
}

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
  request.mockResolvedValue({ data: {} });
  vi.spyOn(console, "error").mockImplementation(() => {});
});

describe("CartLineControls", () => {
  it("asks the API for one more, then redraws the page from its answer", async () => {
    render(<CartLineControls item={line()} />);
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "One more Boss DS-1 distortion pedal" }));

    expect(sent()).toEqual({ path: "/cart/items/7", method: "PATCH", body: { quantity: 3 } });
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("asks for one fewer", async () => {
    render(<CartLineControls item={line()} />);
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "One fewer Boss DS-1 distortion pedal" }));

    expect(sent()).toEqual({ path: "/cart/items/7", method: "PATCH", body: { quantity: 1 } });
  });

  it("removes the line at one, rather than asking for none", async () => {
    render(<CartLineControls item={line({ quantity: 1 })} />);

    expect(screen.queryByRole("button", { name: /One fewer/ })).toBeNull();

    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Remove Boss DS-1 distortion pedal" }));

    expect(sent()).toEqual({ path: "/cart/items/7", method: "DELETE", body: undefined });
  });

  it("offers the quantity the API says is left", async () => {
    render(
      <CartLineControls
        item={line({ availability: "insufficient_stock", quantity: 3, available_quantity: 1 })}
      />,
    );
    await userEvent.setup().click(screen.getByRole("button", { name: "Change to 1" }));

    expect(sent()).toEqual({ path: "/cart/items/7", method: "PATCH", body: { quantity: 1 } });
  });

  it("offers only removal for a line that cannot be bought at all", () => {
    render(<CartLineControls item={line({ availability: "out_of_stock" })} />);

    expect(screen.queryByRole("button", { name: /One more/ })).toBeNull();
    expect(screen.getByRole("button", { name: "Remove Boss DS-1 distortion pedal" })).toBeVisible();
  });

  it("shows a 409 in the API's words and leaves the page as it was", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "Only 4 of these are available.", available: 4 }),
    );

    render(<CartLineControls item={line({ quantity: 4 })} />);
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "One more Boss DS-1 distortion pedal" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Only 4 of these are available.");
    expect(router.refresh).not.toHaveBeenCalled();
  });

  /** Taken out in another tab: already the state the person wanted. */
  it("redraws quietly when the line was already gone", async () => {
    request.mockRejectedValue(new ApiError(404, { message: "Not found." }));

    render(<CartLineControls item={line()} />);
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "One more Boss DS-1 distortion pedal" }));

    expect(router.refresh).toHaveBeenCalledOnce();
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("sends somebody whose session ended to sign in, and back to the cart", async () => {
    request.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));

    render(<CartLineControls item={line()} />);
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "One more Boss DS-1 distortion pedal" }));

    expect(router.push).toHaveBeenCalledWith("/login?next=%2Fcart");
  });
});
