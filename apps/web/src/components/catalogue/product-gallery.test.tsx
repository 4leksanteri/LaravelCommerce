import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";

import type { ProductImage } from "@/lib/api/types";

import { ProductGallery } from "./product-gallery";

const photographs: ProductImage[] = [
  {
    id: "0b7d2c1e-1111-4c8e-9b1a-2d5e6f7a8b90",
    url: "/api/v1/images/0b7d2c1e-1111-4c8e-9b1a-2d5e6f7a8b90",
    width: 1600,
    height: 1200,
    alt_text: "The top plate, showing the shutter speed dial",
    position: 0,
  },
  {
    id: "0b7d2c1e-2222-4c8e-9b1a-2d5e6f7a8b90",
    url: "/api/v1/images/0b7d2c1e-2222-4c8e-9b1a-2d5e6f7a8b90",
    width: 1200,
    height: 1200,
    alt_text: null,
    position: 1,
  },
];

describe("ProductGallery", () => {
  it("shows the first photograph large, described by the seller's own words", () => {
    render(<ProductGallery images={photographs} name="Olympus OM-1 body, serviced" />);

    expect(
      screen.getByRole("img", { name: "The top plate, showing the shutter speed dial" }),
    ).toBeVisible();
  });

  it("switches to a thumbnail's photograph, and says which one is showing", async () => {
    render(<ProductGallery images={photographs} name="Olympus OM-1 body, serviced" />);

    const second = screen.getByRole("button", { name: "Show photograph 2 of 2" });
    await userEvent.setup().click(second);

    // No alt text from the seller, so the listing's name describes it.
    expect(screen.getByRole("img", { name: "Olympus OM-1 body, serviced" })).toBeVisible();
    expect(second).toHaveAttribute("aria-current", "true");
    expect(screen.getByRole("button", { name: "Show photograph 1 of 2" })).not.toHaveAttribute(
      "aria-current",
    );
  });

  it("offers no thumbnails for a single photograph", () => {
    render(<ProductGallery images={[photographs[0]]} name="Olympus OM-1 body, serviced" />);

    expect(screen.queryByRole("button")).toBeNull();
  });

  it("says there is no photograph rather than showing an empty frame", () => {
    render(<ProductGallery images={[]} name="Paterson developing tank" />);

    expect(screen.getByText("No photograph yet")).toBeVisible();
  });
});
