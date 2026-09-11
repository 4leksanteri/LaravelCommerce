import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { PublicProduct } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";

import { AddToCart } from "./add-to-cart";

const router = { push: vi.fn(), refresh: vi.fn() };
const HERE = "/shops/northlight-analog/products/canon-ae-1-program-with-50mm-f18";

vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => HERE }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const add = vi.mocked(apiFetch);

type Variant = PublicProduct["variants"][number];

const chrome: Variant = { id: 11, name: "Chrome", price_minor: 26900, in_stock: true };
const black: Variant = { id: 12, name: "Black", price_minor: 28900, in_stock: true };
const silver: Variant = { id: 13, name: "Silver", price_minor: 27900, in_stock: false };

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
  vi.spyOn(console, "error").mockImplementation(() => {});
});

// Expected prices are formatted by formatMoney rather than typed out: the
// question here is whether the right figure is shown, and a currency symbol in
// source would be a character root CLAUDE.md section 15 keeps out.
const eur = (minor: number) => formatMoney(minor, "EUR");

describe("AddToCart", () => {
  it("shows the chosen option's own price, which follows the choice", async () => {
    render(<AddToCart variants={[chrome, black]} currency="EUR" signInHref={null} />);

    expect(screen.getByText(eur(26900), { selector: "p" })).toBeVisible();

    await userEvent.setup().click(screen.getByRole("radio", { name: /Black/ }));

    expect(screen.getByText(eur(28900), { selector: "p" })).toBeVisible();
  });

  it("will not let a sold-out option be chosen, and starts on one that is not", () => {
    render(<AddToCart variants={[silver, chrome]} currency="EUR" signInHref={null} />);

    expect(screen.getByRole("radio", { name: /Silver/ })).toBeDisabled();
    expect(screen.getByRole("radio", { name: /Chrome/ })).toBeChecked();
  });

  it("asks nothing when there is only one way to buy it", () => {
    render(<AddToCart variants={[chrome]} currency="EUR" signInHref={null} />);

    expect(screen.queryByRole("radio")).toBeNull();
    expect(screen.getByRole("button", { name: "Add to cart" })).toBeEnabled();
  });

  it("adds the chosen variant and nothing else the API would have to trust", async () => {
    add.mockResolvedValue({ data: { item_count: 1 } });
    const user = userEvent.setup();

    render(<AddToCart variants={[chrome, black]} currency="EUR" signInHref={null} />);
    await user.click(screen.getByRole("radio", { name: /Black/ }));
    await user.click(screen.getByRole("button", { name: "Add to cart" }));

    expect(add).toHaveBeenCalledWith(
      "/cart/items",
      expect.objectContaining({ method: "POST", body: JSON.stringify({ variant_id: 12 }) }),
    );
  });

  it("says it was added, and asks the server to redraw the header's count", async () => {
    add.mockResolvedValue({ data: { item_count: 1 } });

    render(<AddToCart variants={[chrome]} currency="EUR" signInHref={null} />);
    await userEvent.setup().click(screen.getByRole("button", { name: "Add to cart" }));

    expect(await screen.findByText(/Added to your cart/)).toBeVisible();
    expect(screen.getByRole("link", { name: "View your cart" })).toHaveAttribute("href", "/cart");
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("shows a 409 in the API's own words, because they say what changed", async () => {
    add.mockRejectedValue(new ApiError(409, { message: "Only 1 of these is left.", available: 1 }));

    render(<AddToCart variants={[chrome]} currency="EUR" signInHref={null} />);
    await userEvent.setup().click(screen.getByRole("button", { name: "Add to cart" }));

    expect(await screen.findByRole("alert")).toHaveTextContent("Only 1 of these is left.");
    expect(router.refresh).not.toHaveBeenCalled();
  });

  it("sends somebody whose session ended to sign in, and back here afterwards", async () => {
    add.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));

    render(<AddToCart variants={[chrome]} currency="EUR" signInHref={null} />);
    await userEvent.setup().click(screen.getByRole("button", { name: "Add to cart" }));

    expect(router.push).toHaveBeenCalledWith(`/login?next=${encodeURIComponent(HERE)}`);
  });

  it("offers a way to sign in instead of a button that would fail, and still lets them choose", async () => {
    render(
      <AddToCart
        variants={[chrome, black]}
        currency="EUR"
        signInHref={`/login?next=${encodeURIComponent(HERE)}`}
      />,
    );

    expect(screen.queryByRole("button", { name: "Add to cart" })).toBeNull();
    expect(screen.getByRole("link", { name: "Sign in to add to your cart" })).toHaveAttribute(
      "href",
      `/login?next=${encodeURIComponent(HERE)}`,
    );

    await userEvent.setup().click(screen.getByRole("radio", { name: /Black/ }));

    expect(screen.getByText(eur(28900), { selector: "p" })).toBeVisible();
  });
});
