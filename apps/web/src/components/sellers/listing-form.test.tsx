import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

import { ListingForm } from "./listing-form";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const CATEGORIES = [
  { id: 3, name: "Watches", depth: 0 },
  { id: 7, name: "Mechanical", depth: 1 },
];

function fill() {
  return render(<ListingForm categories={CATEGORIES} currency="EUR" />);
}

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("ListingForm", () => {
  it("sends the price as minor units, and opens the saved listing", async () => {
    request.mockResolvedValue({ data: { id: 42 } });
    const user = userEvent.setup();

    fill();
    await user.type(screen.getByLabelText("Name"), "Seiko 5 Automatic");
    await user.type(screen.getByLabelText("Description"), "Serviced last year.");
    await user.selectOptions(screen.getByLabelText("Category"), "7");
    await user.clear(screen.getByLabelText("Option"));
    await user.type(screen.getByLabelText("Option"), "Canvas strap");
    await user.type(screen.getByLabelText("Price in EUR"), "249.50");
    await user.clear(screen.getByLabelText("In stock"));
    await user.type(screen.getByLabelText("In stock"), "3");

    await user.click(screen.getByRole("button", { name: "Save as a draft" }));

    expect(request).toHaveBeenCalledWith(
      "/seller/products",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          name: "Seiko 5 Automatic",
          description: "Serviced last year.",
          category_id: 7,
          variants: [{ name: "Canvas strap", price_minor: 24950, stock: 3 }],
        }),
      }),
    );
    expect(router.push).toHaveBeenCalledWith("/seller/listings/42");
  });

  /** No category is a draft the API accepts; it is publishing that needs one. */
  it("sends no category when none was chosen", async () => {
    request.mockResolvedValue({ data: { id: 1 } });
    const user = userEvent.setup();

    fill();
    await user.type(screen.getByLabelText("Name"), "A thing");
    await user.type(screen.getByLabelText("Price in EUR"), "10");
    await user.click(screen.getByRole("button", { name: "Save as a draft" }));

    const body = JSON.parse((request.mock.calls[0]?.[1] as { body: string }).body) as Record<
      string,
      unknown
    >;

    expect(body.category_id).toBeNull();
  });

  /**
   * The one refusal on this form that is not the API's: a price it could not
   * read never becomes a request, because there is no figure to send.
   */
  it("refuses a price it cannot read, and sends nothing", async () => {
    const user = userEvent.setup();

    fill();
    await user.type(screen.getByLabelText("Name"), "A thing");
    await user.type(screen.getByLabelText("Price in EUR"), "about a tenner");
    await user.click(screen.getByRole("button", { name: "Save as a draft" }));

    expect(await screen.findByText(/Write the price as a plain amount in EUR/)).toBeVisible();
    expect(request).not.toHaveBeenCalled();
  });

  it("puts the API's refusals beside the fields they name", async () => {
    request.mockRejectedValue(
      new ApiError(422, {
        message: "The given data was invalid.",
        errors: {
          name: ["The name field is required."],
          "variants.0.price_minor": ["The price must be at least 0."],
        },
      }),
    );
    const user = userEvent.setup();

    fill();
    await user.type(screen.getByLabelText("Name"), "x");
    await user.type(screen.getByLabelText("Price in EUR"), "5");
    await user.click(screen.getByRole("button", { name: "Save as a draft" }));

    expect(await screen.findByText("The name field is required.")).toBeVisible();
    expect(screen.getByText("The price must be at least 0.")).toBeVisible();
    expect(router.push).not.toHaveBeenCalled();
  });
});
