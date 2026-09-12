import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Product } from "@/lib/api/types";

import { ListingVariants } from "./listing-variants";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const listing: Product = {
  id: 12,
  name: "Seiko 5 Automatic",
  slug: "seiko-5-automatic",
  description: null,
  status: "draft",
  currency: "EUR",
  published_at: null,
  created_at: "2026-03-01T10:00:00+00:00",
  updated_at: "2026-03-01T10:00:00+00:00",
  variants: [
    { id: 1, name: "Canvas strap", price_minor: 24950, stock: 3, position: 0, in_stock: true },
    { id: 2, name: "Steel bracelet", price_minor: 27900, stock: 0, position: 1, in_stock: false },
  ],
  images: [],
  category: null,
  can_edit: true,
  can_publish: true,
  is_public: false,
};

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("ListingVariants", () => {
  it("shows each option's own price and stock", () => {
    render(<ListingVariants listing={listing} />);

    expect(screen.getByText(/\u20ac249\.50, 3 in stock/)).toBeVisible();
    expect(screen.getByText(/\u20ac279\.00, none left/)).toBeVisible();
  });

  it("saves a changed price as minor units", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<ListingVariants listing={listing} />);
    await user.click(screen.getAllByRole("button", { name: "Change" })[0]!);

    const price = screen.getByLabelText("Price in EUR");
    expect(price).toHaveValue("249.50");

    await user.clear(price);
    await user.type(price, "199,95");
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(request).toHaveBeenCalledWith(
      "/seller/products/12/variants/1",
      expect.objectContaining({
        method: "PATCH",
        body: JSON.stringify({ name: "Canvas strap", price_minor: 19995, stock: 3 }),
      }),
    );
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("refuses a price it cannot read, and sends nothing", async () => {
    const user = userEvent.setup();

    render(<ListingVariants listing={listing} />);
    await user.click(screen.getAllByRole("button", { name: "Change" })[0]!);

    const price = screen.getByLabelText("Price in EUR");
    await user.clear(price);
    await user.type(price, "24.999");
    await user.click(screen.getByRole("button", { name: "Save" }));

    expect(await screen.findByText(/Write the price as a plain amount in EUR/)).toBeVisible();
    expect(request).not.toHaveBeenCalled();
  });

  it("adds an option", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<ListingVariants listing={listing} />);
    await user.click(screen.getByRole("button", { name: "Add an option" }));

    const form = screen.getByRole("form", { name: "Add an option" });
    await user.type(screen.getByLabelText("Option"), "Leather strap");
    await user.type(screen.getByLabelText("Price in EUR"), "259");
    await user.click(form.querySelector("button[type=submit]")!);

    expect(request).toHaveBeenCalledWith(
      "/seller/products/12/variants",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ name: "Leather strap", price_minor: 25900, stock: 1 }),
      }),
    );
  });

  /** The listing would have no price at all, and the API says so (ADR 0009). */
  it("shows the API's reason when the last option cannot be removed", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "A product must keep at least one variant." }),
    );
    const user = userEvent.setup();

    render(<ListingVariants listing={listing} />);
    await user.click(screen.getAllByRole("button", { name: "Remove" })[0]!);

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "A product must keep at least one variant.",
    );
    expect(router.refresh).not.toHaveBeenCalled();
  });

  it("removes an option", async () => {
    request.mockResolvedValue(undefined);
    const user = userEvent.setup();

    render(<ListingVariants listing={listing} />);
    await user.click(screen.getAllByRole("button", { name: "Remove" })[1]!);

    expect(request).toHaveBeenCalledWith("/seller/products/12/variants/2", { method: "DELETE" });
    expect(router.refresh).toHaveBeenCalledOnce();
  });
});
