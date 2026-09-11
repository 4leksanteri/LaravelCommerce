import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { PublicProduct } from "@/lib/api/types";

import { ProductCard } from "./product-card";

const NBSP = "\u00a0";

function listing(overrides: Partial<PublicProduct> = {}): PublicProduct {
  return {
    slug: "seiko-5-automatic-snk809",
    name: "Seiko 5 automatic, SNK809",
    description: "Keeps time within ten seconds a day.",
    currency: "DKK",
    shop_slug: "second-hand-time",
    shop_name: "Second Hand Time",
    category: null,
    images: [],
    variants: [],
    price_from_minor: 95000,
    price_to_minor: 95000,
    in_stock: true,
    ...overrides,
  };
}

function price(): HTMLElement {
  return screen.getByText(
    (_, element) => element?.tagName === "P" && /\d\.\d{2}$/.test(element.textContent ?? ""),
  );
}

describe("ProductCard", () => {
  it("always says which shop it is from - the buyer is buying from a shop, not from us", () => {
    render(<ProductCard product={listing()} />);

    expect(screen.getByText("Second Hand Time")).toBeVisible();
  });

  it("shows one price when there is one", () => {
    render(<ProductCard product={listing()} />);

    expect(price().textContent).toBe(`DKK${NBSP}950.00`);
  });

  /**
   * The regression. "from" and the price once sat in adjacent elements
   * separated only by a CSS margin, which reads as one word - "fromDKK" - to
   * anything that is not looking at the page.
   */
  it("says 'from', with a real space, when the sizes cost different amounts", () => {
    render(<ProductCard product={listing({ price_to_minor: 115000 })} />);

    expect(price().textContent).toBe(`from DKK${NBSP}950.00`);
  });

  it("shows the API's figure, not one it worked out itself", () => {
    // Variants that would give a different minimum if the card computed one.
    render(
      <ProductCard
        product={listing({
          variants: [
            { id: 1, name: "Cheaper", price_minor: 100, in_stock: true },
            { id: 2, name: "Dearer", price_minor: 999999, in_stock: true },
          ],
        })}
      />,
    );

    expect(price().textContent).toBe(`DKK${NBSP}950.00`);
  });

  it("says when nothing is for sale, and still says what it cost", () => {
    render(<ProductCard product={listing({ in_stock: false })} />);

    expect(screen.getByText("Sold out")).toBeVisible();
    expect(price().textContent).toBe(`DKK${NBSP}950.00`);
  });

  it("says it has no photograph rather than showing an empty box", () => {
    render(<ProductCard product={listing()} />);

    expect(screen.getByText("No photograph yet")).toBeVisible();
  });

  it("describes a photograph with the product's name when the seller wrote no alt text", () => {
    render(
      <ProductCard
        product={listing({
          images: [
            {
              // A UUID, not a sequence number: an image's key is unguessable so
              // that knowing one does not reveal the next (ADR 0016).
              id: "9f3c2b1e-7a4d-4c8e-9b1a-2d5e6f7a8b9c",
              url: "/api/v1/images/9f3c2b1e-7a4d-4c8e-9b1a-2d5e6f7a8b9c",
              width: 1600,
              height: 1200,
              alt_text: null,
              position: 0,
            },
          ],
        })}
      />,
    );

    expect(screen.getByRole("img")).toHaveAttribute("alt", "Seiko 5 automatic, SNK809");
  });

  it("is one link, named for the product, to the listing's permanent address", () => {
    render(<ProductCard product={listing()} />);

    const link = screen.getByRole("link");

    expect(link).toHaveAccessibleName("Seiko 5 automatic, SNK809");
    expect(link).toHaveAttribute(
      "href",
      "/shops/second-hand-time/products/seiko-5-automatic-snk809",
    );
  });

  it("shows no price at all rather than a wrong one when the API has none", () => {
    render(<ProductCard product={listing({ price_from_minor: null, price_to_minor: null })} />);

    expect(screen.queryByText(/\d\.\d{2}$/)).toBeNull();
  });
});
