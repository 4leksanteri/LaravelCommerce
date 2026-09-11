import { expect, test } from "@playwright/test";

import { emptyCart, SHOPPER_SESSION } from "./support/session";

/**
 * A listing, against the demo catalogue - photographs included, which
 * `make e2e` puts there through the API's real upload code.
 *
 * The one test that proves the image arrangement end to end is here: a
 * photograph uploaded through StoreProductImage, served by the API under
 * /api/v1/images, forwarded by the proxy, and resized by Next's optimiser under
 * the `localPatterns` rule ADR 0024 wrote. Nothing had exercised that chain in
 * a browser before.
 */

const OM1 = "/shops/northlight-analog/products/olympus-om-1-body-serviced";
const CANON = "/shops/northlight-analog/products/canon-ae-1-program-with-50mm-f18";
const BIG_MUFF = "/shops/fret-and-valve/products/electro-harmonix-big-muff-pi";

// Currency symbols as escapes, which root CLAUDE.md section 15 asks for.
const EURO = "\u20ac";

test("a card leads to its listing, which says what it is, who sells it and what it costs", async ({
  page,
}) => {
  await page.goto("/categories/film-cameras");
  await page.getByRole("link", { name: "Canon AE-1 Program with 50mm f/1.8" }).click();

  await expect(page).toHaveURL(new RegExp(`${CANON}$`));
  await expect(page.getByRole("heading", { level: 1 })).toHaveText(
    "Canon AE-1 Program with 50mm f/1.8",
  );
  await expect(page.getByText("Northlight Analog")).toBeVisible();
  await expect(page.getByText("The squeak has been dealt with.")).toBeVisible();
  await expect(page.getByRole("link", { name: "Film cameras" })).toHaveAttribute(
    "href",
    "/categories/film-cameras",
  );
});

test("its photographs come through the proxy and Next's image optimiser", async ({ page }) => {
  await page.goto(OM1);

  const photograph = page.getByRole("img", { name: "Olympus OM-1 body, serviced" }).first();
  await expect(photograph).toBeVisible();

  const src = await photograph.getAttribute("src");
  expect(src).toMatch(/^\/_next\/image\?url=%2Fapi%2Fv1%2Fimages%2F/);

  const response = await page.request.get(src as string);
  expect(response.status()).toBe(200);
  expect(response.headers()["content-type"]).toMatch(/^image\//);

  // Three photographs on this listing, so the gallery offers the other two.
  await expect(page.getByRole("button", { name: "Show photograph 3 of 3" })).toBeVisible();
});

test("choosing an option shows that option's own price", async ({ page }) => {
  await page.goto(CANON);

  // The headline price is a <p>. Each option's label also shows its own price,
  // in a <span>, so an unscoped text search finds both and cannot say which one
  // moved - which is how the first version of this test failed.
  const headline = (amount: string) =>
    page.locator("p").filter({ hasText: new RegExp(`^${EURO}${amount.replace(".", "\\.")}$`) });

  await expect(headline("269.00")).toBeVisible();

  await page.getByRole("radio", { name: /Black/ }).check();

  await expect(headline("289.00")).toBeVisible();
  await expect(headline("269.00")).toHaveCount(0);
});

test("somebody signed out is offered a way to sign in and come back", async ({ page }) => {
  await page.goto(OM1);

  await expect(page.getByRole("button", { name: "Add to cart" })).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Sign in to add to your cart" })).toHaveAttribute(
    "href",
    `/login?next=${encodeURIComponent(OM1)}`,
  );
});

test.describe("signed in as the demo shopper", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("a signed-in shopper adds it, and the header's count follows", async ({ page }) => {
    await emptyCart(page);
    await page.goto(OM1);

    await page.getByRole("button", { name: "Add to cart" }).click();

    await expect(page.getByText("Added to your cart.")).toBeVisible();
    await expect(page.getByRole("link", { name: "Cart, 1 item" })).toBeVisible();
  });
});

test("a listing with nothing left says so, and offers no way to buy it", async ({ page }) => {
  await page.goto(BIG_MUFF);

  await expect(page.getByText("Sold out", { exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Add to cart" })).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Sign in to add to your cart" })).toHaveCount(0);
});

test("a listing that does not exist is the not-found page", async ({ page }) => {
  const response = await page.goto("/shops/northlight-analog/products/no-such-listing");

  expect(response?.status()).toBe(404);
  await expect(page.getByRole("heading", { name: "Nothing lives at this address." })).toBeVisible();
});
