import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Product } from "@/lib/api/types";

import { ListingImages } from "./listing-images";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const bare: Product = {
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

const withPhotograph: Product = {
  ...bare,
  images: [
    {
      id: "7d1f3c9a",
      // A draft's photograph carries a signature that expires (ADR 0016).
      url: "/api/v1/images/7d1f3c9a?signature=abc",
      width: 1200,
      height: 900,
      alt_text: null,
      position: 0,
    },
  ],
};

function aFile() {
  return new File(["not really an image"], "watch.png", { type: "image/png" });
}

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("ListingImages", () => {
  /**
   * The browser writes the content type, with its boundary. Setting one by hand
   * produces a body the API cannot parse.
   */
  it("uploads as multipart, without naming the content type", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<ListingImages listing={bare} limit={8} />);
    await user.upload(screen.getByLabelText("Add a photograph"), aFile());

    const [path, init] = request.mock.calls[0] as [string, RequestInit];

    expect(path).toBe("/seller/products/12/images");
    expect(init.method).toBe("POST");
    expect(init.body).toBeInstanceOf(FormData);
    expect((init.body as FormData).get("image")).toBeInstanceOf(File);
    expect(init.headers).toBeUndefined();
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("shows the API's reason when the listing already has its limit", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "The listing already has as many images as it may have." }),
    );
    const user = userEvent.setup();

    render(<ListingImages listing={bare} limit={8} />);
    await user.upload(screen.getByLabelText("Add a photograph"), aFile());

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The listing already has as many images as it may have.",
    );
  });

  it("puts a refused file beside the field", async () => {
    const message = "The image must be a file of type: jpeg, jpg, png, webp.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { image: [message] } }));
    const user = userEvent.setup();

    render(<ListingImages listing={bare} limit={8} />);
    await user.upload(screen.getByLabelText("Add a photograph"), aFile());

    expect(await screen.findByText(message)).toBeVisible();
  });

  it("stops offering the upload once the limit is reached", () => {
    render(<ListingImages listing={withPhotograph} limit={1} />);

    expect(screen.getByLabelText("Add a photograph")).toBeDisabled();
    expect(screen.getByText(/has the most photographs it may have \(1\)/)).toBeVisible();
  });

  it("saves a photograph's description on its own", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<ListingImages listing={withPhotograph} limit={8} />);
    await user.type(
      screen.getByLabelText("What is in the photograph"),
      "The watch on its canvas strap",
    );
    await user.click(screen.getByRole("button", { name: "Save the description" }));

    expect(request).toHaveBeenCalledWith(
      "/seller/products/12/images/7d1f3c9a",
      expect.objectContaining({
        method: "PATCH",
        body: JSON.stringify({ alt_text: "The watch on its canvas strap" }),
      }),
    );
  });

  /** Empty means nobody wrote one, which is not the same as "decorative". */
  it("sends null rather than an empty description", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(
      <ListingImages
        listing={{
          ...withPhotograph,
          images: [{ ...withPhotograph.images[0]!, alt_text: "Something" }],
        }}
        limit={8}
      />,
    );
    await user.clear(screen.getByLabelText("What is in the photograph"));
    await user.click(screen.getByRole("button", { name: "Save the description" }));

    expect(request).toHaveBeenCalledWith(
      "/seller/products/12/images/7d1f3c9a",
      expect.objectContaining({ body: JSON.stringify({ alt_text: null }) }),
    );
  });

  it("removes a photograph", async () => {
    request.mockResolvedValue(undefined);
    const user = userEvent.setup();

    render(<ListingImages listing={withPhotograph} limit={8} />);
    await user.click(screen.getByRole("button", { name: "Remove" }));

    expect(request).toHaveBeenCalledWith("/seller/products/12/images/7d1f3c9a", {
      method: "DELETE",
    });
    expect(router.refresh).toHaveBeenCalledOnce();
  });
});
