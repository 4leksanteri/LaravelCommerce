import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Address } from "@/lib/api/types";
import { loadFresh } from "@/lib/navigation";

import { CheckoutForm } from "./checkout-form";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/checkout" }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));
// jsdom cannot navigate, so the full page load is replaced and asked about.
vi.mock("@/lib/navigation", () => ({ loadFresh: vi.fn() }));

const request = vi.mocked(apiFetch);
const leave = vi.mocked(loadFresh);

const home: Address = {
  id: 12,
  name: "Aino Virtanen",
  line1: "Mannerheimintie 12 A 4",
  line2: null,
  city: "Helsinki",
  region: null,
  postal_code: "00100",
  country: "FI",
  phone: null,
};

const office: Address = { ...home, id: 9, name: "Aino at work", line1: "Keilaranta 1" };

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
  leave.mockReset();
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const place = () => userEvent.setup().click(screen.getByRole("button", { name: "Place orders" }));

describe("CheckoutForm", () => {
  it("starts on the newest address, which the API lists first", () => {
    render(<CheckoutForm addresses={[home, office]} />);

    expect(screen.getByRole("radio", { name: /Mannerheimintie 12 A 4/ })).toBeChecked();
    expect(screen.getByRole("radio", { name: /Keilaranta 1/ })).not.toBeChecked();
  });

  it("opens the address form straight away when the book is empty, and cannot place orders yet", () => {
    render(<CheckoutForm addresses={[]} />);

    expect(screen.getByRole("form", { name: "New address" })).toBeVisible();
    expect(screen.getByRole("button", { name: "Place orders" })).toBeDisabled();
  });

  /**
   * A full page load into the confirmation, not a client-side navigation - which
   * would keep the header as it was drawn, still counting a full basket.
   */
  it("sends the chosen address and nothing else, then loads the confirmation afresh", async () => {
    request.mockResolvedValue({
      data: [{ reference: "K7M2QXV9RT" }, { reference: "8Y9JN63MTC" }],
    });
    const user = userEvent.setup();

    render(<CheckoutForm addresses={[home, office]} />);
    await user.click(screen.getByRole("radio", { name: /Keilaranta 1/ }));
    await user.click(screen.getByRole("button", { name: "Place orders" }));

    expect(request).toHaveBeenCalledWith(
      "/checkout",
      expect.objectContaining({ method: "POST", body: JSON.stringify({ address_id: 9 }) }),
    );
    expect(leave).toHaveBeenCalledWith(
      `/checkout/placed?orders=${encodeURIComponent("K7M2QXV9RT,8Y9JN63MTC")}`,
    );
    expect(router.push).not.toHaveBeenCalled();
  });

  /**
   * The basket moved between drawing the page and pressing the button. The
   * page is redrawn, and the cart's own answer then says what is in the way.
   */
  it("redraws the page when the basket can no longer be checked out", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "Some of these can no longer be bought.", items: [] }),
    );

    render(<CheckoutForm addresses={[home]} />);
    await place();

    expect(router.refresh).toHaveBeenCalledOnce();
    expect(leave).not.toHaveBeenCalled();
  });

  it("says so when the chosen address has gone, and redraws the list", async () => {
    request.mockRejectedValue(new ApiError(404, { message: "Not found." }));

    render(<CheckoutForm addresses={[home]} />);
    await place();

    expect(await screen.findByText(/no longer in your address book/)).toBeVisible();
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("shows the API's reason for a 403 rather than a sentence written for another screen", async () => {
    request.mockRejectedValue(
      new ApiError(403, { message: "Your email address is not verified." }),
    );

    render(<CheckoutForm addresses={[home]} />);
    await place();

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Your email address is not verified.",
    );
  });

  it("sends somebody whose session ended to sign in, and back to checkout", async () => {
    request.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));

    render(<CheckoutForm addresses={[home]} />);
    await place();

    expect(router.push).toHaveBeenCalledWith("/login?next=%2Fcheckout");
  });
});
