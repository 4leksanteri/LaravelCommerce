import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Shop } from "@/lib/api/types";

import { ShopDetailsForm } from "./shop-details-form";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const shop: Shop = {
  id: 7,
  shop_name: "Second Hand Time",
  slug: "second-hand-time",
  description: "Serviced watches.",
  contact_email: "watches@example.test",
  currency: "EUR",
  status: "approved",
  rejection_reason: null,
  applied_at: "2026-03-01T10:00:00+00:00",
  reviewed_at: "2026-03-02T10:00:00+00:00",
  can_edit: true,
  can_review: false,
  is_public: true,
};

beforeEach(() => {
  router.refresh.mockReset();
});

describe("ShopDetailsForm", () => {
  /**
   * What is on screen is what is sent, all of it, and the page is drawn again
   * from the API - which is what updates the name in the sidebar beside it.
   */
  it("saves what is on screen and redraws the page", async () => {
    request.mockResolvedValue({ data: shop });
    const user = userEvent.setup();

    render(<ShopDetailsForm shop={shop} />);
    await user.clear(screen.getByLabelText("Shop name"));
    await user.type(screen.getByLabelText("Shop name"), "Second Hand Time & Co");
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(request).toHaveBeenCalledWith(
      "/seller",
      expect.objectContaining({
        method: "PATCH",
        body: JSON.stringify({
          shop_name: "Second Hand Time & Co",
          description: "Serviced watches.",
          contact_email: "watches@example.test",
        }),
      }),
    );
    expect(await screen.findByText("Saved.")).toBeVisible();
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("puts the API's refusal beside the field, and does not say it saved", async () => {
    const message = "The contact email field must be a valid email address.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { contact_email: [message] } }));
    const user = userEvent.setup();

    render(<ShopDetailsForm shop={shop} />);
    await user.clear(screen.getByLabelText("Contact email"));
    await user.type(screen.getByLabelText("Contact email"), "not an address");
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.queryByText("Saved.")).not.toBeInTheDocument();
    expect(router.refresh).not.toHaveBeenCalled();
  });
});
