import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Shop } from "@/lib/api/types";

import { ShopSuspensionActions } from "./shop-suspension-actions";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const trading: Shop = {
  id: 7,
  shop_name: "Second Hand Time",
  slug: "second-hand-time",
  description: "Mechanical watches, regulated and pressure-tested.",
  contact_email: "shop@example.test",
  currency: "EUR",
  status: "approved",
  rejection_reason: null,
  suspension_reason: null,
  applied_at: "2026-03-01T10:00:00+00:00",
  reviewed_at: "2026-03-02T10:00:00+00:00",
  can_edit: false,
  can_review: true,
  can_suspend: true,
  is_public: true,
};

const suspended: Shop = {
  ...trading,
  status: "suspended",
  suspension_reason: "Three disputes decided against it.",
  is_public: false,
};

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
  request.mockReset();
  request.mockResolvedValue({ data: suspended });
});

describe("ShopSuspensionActions", () => {
  // --- Stopping one --------------------------------------------------------

  it("offers to suspend a shop that is trading", () => {
    render(<ShopSuspensionActions shop={trading} />);

    expect(screen.getByRole("button", { name: "Suspend this shop" })).toBeVisible();
  });

  /**
   * The form is the pause. Suspending stops somebody's livelihood, and the
   * reason is required anyway because the shop is sent it (ADR 0052).
   */
  it("asks why before sending anything", async () => {
    const user = userEvent.setup();

    render(<ShopSuspensionActions shop={trading} />);
    await user.click(screen.getByRole("button", { name: "Suspend this shop" }));

    expect(request).not.toHaveBeenCalled();
    expect(screen.getByLabelText("Why are you suspending it?")).toHaveFocus();

    // What a suspension does not do is said where the decision is taken.
    expect(screen.getByText(/Orders it has already taken are not cancelled/)).toBeVisible();
  });

  it("sends the reason, and redraws the queue", async () => {
    const user = userEvent.setup();

    render(<ShopSuspensionActions shop={trading} />);
    await user.click(screen.getByRole("button", { name: "Suspend this shop" }));
    await user.type(
      screen.getByLabelText("Why are you suspending it?"),
      "Three disputes decided against it.",
    );
    await user.click(screen.getByRole("button", { name: "Suspend the shop" }));

    expect(request).toHaveBeenCalledWith(
      "/admin/sellers/7/suspension",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ reason: "Three disputes decided against it." }),
      }),
    );
    expect(router.refresh).toHaveBeenCalled();
  });

  it("puts the API's refusal beside the field", async () => {
    const message = "The reason must be at least 10 characters.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { reason: [message] } }));
    const user = userEvent.setup();

    render(<ShopSuspensionActions shop={trading} />);
    await user.click(screen.getByRole("button", { name: "Suspend this shop" }));
    await user.type(screen.getByLabelText("Why are you suspending it?"), "no");
    await user.click(screen.getByRole("button", { name: "Suspend the shop" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.getByLabelText("Why are you suspending it?")).toHaveAttribute(
      "aria-invalid",
      "true",
    );
  });

  it("sends somebody whose session ended to sign in, and back to the queue", async () => {
    request.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));
    const user = userEvent.setup();

    render(<ShopSuspensionActions shop={trading} />);
    await user.click(screen.getByRole("button", { name: "Suspend this shop" }));
    await user.type(screen.getByLabelText("Why are you suspending it?"), "Enough reason here.");
    await user.click(screen.getByRole("button", { name: "Suspend the shop" }));

    await waitFor(() => expect(router.push).toHaveBeenCalledWith("/login?next=%2Fadmin%2Fshops"));
  });

  /** Another member of staff got there first. */
  it("shows the API's reason when the shop has moved on, and redraws", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "Only an open shop can be suspended.", status: "suspended" }),
    );
    const user = userEvent.setup();

    render(<ShopSuspensionActions shop={trading} />);
    await user.click(screen.getByRole("button", { name: "Suspend this shop" }));
    await user.type(screen.getByLabelText("Why are you suspending it?"), "Enough reason here.");
    await user.click(screen.getByRole("button", { name: "Suspend the shop" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "Only an open shop can be suspended.",
    );
    expect(router.refresh).toHaveBeenCalled();
  });

  it("sends nothing when the decision is abandoned", async () => {
    const user = userEvent.setup();

    render(<ShopSuspensionActions shop={trading} />);
    await user.click(screen.getByRole("button", { name: "Suspend this shop" }));
    await user.click(screen.getByRole("button", { name: "Leave it trading" }));

    expect(request).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Suspend this shop" })).toBeVisible();
  });

  // --- Letting it start again ----------------------------------------------

  /**
   * One click, deliberately. It is the reversible direction, and asking "are
   * you sure" before the benign thing is how people learn to click through
   * questions without reading them.
   */
  it("reinstates a suspended shop on one click", async () => {
    const user = userEvent.setup();

    render(<ShopSuspensionActions shop={suspended} />);

    expect(screen.queryByRole("button", { name: "Suspend this shop" })).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Let it trade again" }));

    expect(request).toHaveBeenCalledWith(
      "/admin/sellers/7/suspension",
      expect.objectContaining({ method: "DELETE" }),
    );
    expect(router.refresh).toHaveBeenCalled();
  });
});
