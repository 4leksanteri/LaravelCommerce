import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Product } from "@/lib/api/types";

import { ListingPublication } from "./listing-publication";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const draft: Product = {
  id: 12,
  name: "Seiko 5 Automatic",
  slug: "seiko-5-automatic",
  description: null,
  status: "draft",
  currency: "EUR",
  published_at: null,
  created_at: "2026-03-01T10:00:00+00:00",
  updated_at: "2026-03-01T10:00:00+00:00",
  variants: [],
  images: [],
  category: null,
  can_edit: true,
  can_publish: true,
  is_public: false,
};

const onSale: Product = {
  ...draft,
  status: "published",
  published_at: "2026-03-02T10:00:00+00:00",
  is_public: true,
};

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("ListingPublication", () => {
  it("puts a draft on sale", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<ListingPublication listing={draft} />);
    await user.click(screen.getByRole("button", { name: "Put it on sale" }));

    expect(request).toHaveBeenCalledWith("/seller/products/12/publication", { method: "POST" });
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("takes one off sale again", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<ListingPublication listing={onSale} />);
    await user.click(screen.getByRole("button", { name: "Take it off sale" }));

    expect(request).toHaveBeenCalledWith("/seller/products/12/publication", { method: "DELETE" });
  });

  /**
   * `can_publish` is ownership and nothing else: whether the shop is approved,
   * or a category chosen, is the API's 409 to explain (ADR 0008).
   */
  it("shows the API's reason when the listing cannot go on sale yet", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "A listing needs a category before it can go on sale." }),
    );
    const user = userEvent.setup();

    render(<ListingPublication listing={draft} />);
    await user.click(screen.getByRole("button", { name: "Put it on sale" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "A listing needs a category before it can go on sale.",
    );
  });

  it("offers nothing to put on sale when the API does not allow it", () => {
    render(<ListingPublication listing={{ ...draft, can_publish: false }} />);

    expect(screen.queryByRole("button", { name: "Put it on sale" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Delete this listing" })).toBeVisible();
  });

  it("asks before deleting, and sends nothing on a no", async () => {
    const user = userEvent.setup();

    render(<ListingPublication listing={draft} />);
    await user.click(screen.getByRole("button", { name: "Delete this listing" }));

    expect(screen.getByRole("button", { name: "Yes, delete it" })).toHaveFocus();

    await user.click(screen.getByRole("button", { name: "Keep it" }));

    expect(request).not.toHaveBeenCalled();
  });

  it("deletes the listing and goes back to the catalogue", async () => {
    request.mockResolvedValue(undefined);
    const user = userEvent.setup();

    render(<ListingPublication listing={draft} />);
    await user.click(screen.getByRole("button", { name: "Delete this listing" }));
    await user.click(screen.getByRole("button", { name: "Yes, delete it" }));

    expect(request).toHaveBeenCalledWith("/seller/products/12", { method: "DELETE" });
    expect(router.push).toHaveBeenCalledWith("/seller/listings");
  });
});
