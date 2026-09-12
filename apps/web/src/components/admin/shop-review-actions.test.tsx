import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Shop } from "@/lib/api/types";

import { ShopReviewActions } from "./shop-review-actions";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const waiting: Shop = {
  id: 7,
  shop_name: "Bench and Bellows",
  slug: "bench-and-bellows",
  description: "Repaired accordions and squeezeboxes, each one played before it is listed.",
  contact_email: "hopeful@example.test",
  currency: "EUR",
  status: "pending",
  rejection_reason: null,
  applied_at: "2026-03-01T10:00:00+00:00",
  reviewed_at: null,
  can_edit: false,
  can_review: true,
  is_public: false,
};

/** The policy refuses a reviewer their own application (ADR 0008). */
const theirOwn: Shop = { ...waiting, can_review: false, can_edit: true };

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("ShopReviewActions", () => {
  it("draws nothing when the API says this account may not review it", () => {
    render(<ShopReviewActions shop={theirOwn} />);

    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("asks before approving, and sends nothing on a no", async () => {
    const user = userEvent.setup();

    render(<ShopReviewActions shop={waiting} />);
    await user.click(screen.getByRole("button", { name: "Approve" }));

    expect(request).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Yes, let it trade" })).toHaveFocus();

    await user.click(screen.getByRole("button", { name: "Go back" }));

    expect(request).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Approve" })).toBeVisible();
  });

  it("approves once the question is answered", async () => {
    request.mockResolvedValue({ data: { ...waiting, status: "approved" } });
    const user = userEvent.setup();

    render(<ShopReviewActions shop={waiting} />);
    await user.click(screen.getByRole("button", { name: "Approve" }));
    await user.click(screen.getByRole("button", { name: "Yes, let it trade" }));

    expect(request).toHaveBeenCalledWith("/admin/sellers/7/approval", { method: "POST" });
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("asks for the reason before turning one down, and sends it", async () => {
    request.mockResolvedValue({ data: { ...waiting, status: "rejected" } });
    const user = userEvent.setup();

    render(<ShopReviewActions shop={waiting} />);
    await user.click(screen.getByRole("button", { name: "Turn it down" }));

    expect(request).not.toHaveBeenCalled();
    expect(screen.getByLabelText("Why are you turning it down?")).toHaveFocus();

    await user.type(
      screen.getByLabelText("Why are you turning it down?"),
      "The contact address bounces.",
    );
    await user.click(screen.getByRole("button", { name: "Send the decision" }));

    expect(request).toHaveBeenCalledWith(
      "/admin/sellers/7/rejection",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ reason: "The contact address bounces." }),
      }),
    );
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("puts the API's refusal beside the reason", async () => {
    const message = "The reason field must be at least 10 characters.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { reason: [message] } }));
    const user = userEvent.setup();

    render(<ShopReviewActions shop={waiting} />);
    await user.click(screen.getByRole("button", { name: "Turn it down" }));
    await user.type(screen.getByLabelText("Why are you turning it down?"), "no");
    await user.click(screen.getByRole("button", { name: "Send the decision" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.getByLabelText("Why are you turning it down?")).toHaveAttribute(
      "aria-invalid",
      "true",
    );
  });

  /** Two reviewers with the queue open, and the other one got there first. */
  it("shows the API's reason when somebody else has decided, and redraws the queue", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "This application has already been reviewed." }),
    );
    const user = userEvent.setup();

    render(<ShopReviewActions shop={waiting} />);
    await user.click(screen.getByRole("button", { name: "Approve" }));
    await user.click(screen.getByRole("button", { name: "Yes, let it trade" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "This application has already been reviewed.",
    );
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("sends somebody whose session ended to sign in, and back to the queue", async () => {
    request.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));
    const user = userEvent.setup();

    render(<ShopReviewActions shop={waiting} />);
    await user.click(screen.getByRole("button", { name: "Approve" }));
    await user.click(screen.getByRole("button", { name: "Yes, let it trade" }));

    expect(router.push).toHaveBeenCalledWith("/login?next=%2Fadmin%2Fshops");
  });
});
