import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

import { emptyCart, SHOPPER, SHOPPER_SESSION } from "./support/session";

/**
 * The cart, against the demo catalogue.
 *
 * The two listings are chosen for what they exercise: the OM-1 is EUR with one
 * in stock, the DS-1 is GBP with four. Two currencies means two groups and no
 * total across them; stock of one means the next one is refused.
 */

const OM1 = "/shops/northlight-analog/products/olympus-om-1-body-serviced";
const DS1 = "/shops/fret-and-valve/products/boss-ds-1-distortion-pedal";

// Escapes rather than the symbols, which root CLAUDE.md section 15 keeps out.
const EURO = "\u20ac";
const POUND = "\u00a3";

async function addToCart(page: Page, path: string) {
  await page.goto(path);
  await page.getByRole("button", { name: "Add to cart" }).click();
  await expect(page.getByText("Added to your cart.")).toBeVisible();
}

/**
 * Its own context, with nobody signed in - not the shared session. Signing in
 * here makes a second session for the same shopper, and leaves the shared one
 * alone.
 */
test("the cart needs somebody signed in, and brings them back to it afterwards", async ({
  page,
}) => {
  await page.goto("/cart");
  await expect(page).toHaveURL(/\/login\?next=%2Fcart$/);

  await page.getByLabel("Email").fill(SHOPPER.email);
  await page.getByLabel("Password").fill(SHOPPER.password);
  await page.getByRole("button", { name: "Sign in" }).click();

  await expect(page).toHaveURL(/\/cart$/);
  await expect(page.getByRole("heading", { level: 1, name: "Your cart" })).toBeVisible();
});

test.describe("signed in as the demo shopper", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test.beforeEach(async ({ page }) => {
    await emptyCart(page);
  });

  test("an empty cart says so, and offers somewhere to go", async ({ page }) => {
    await page.goto("/cart");

    await expect(page.getByText("Your cart is empty.")).toBeVisible();
    await expect(page.getByRole("link", { name: "Browse everything" })).toBeVisible();
  });

  test("lines are grouped by shop, each in its own currency, with no total across them", async ({
    page,
  }) => {
    await addToCart(page, OM1);
    await addToCart(page, DS1);
    await page.goto("/cart");

    const northlight = page.getByRole("region", { name: "Northlight Analog" });
    const fret = page.getByRole("region", { name: "Fret & Valve" });

    await expect(northlight).toContainText("Olympus OM-1 body, serviced");
    await expect(northlight).toContainText(`${EURO}219.00`);
    await expect(fret).toContainText("Boss DS-1 distortion pedal");
    await expect(fret).toContainText(`${POUND}35.00`);
    await expect(page.getByText(/no total across them/)).toBeVisible();
  });

  test("a quantity changes through the API, which does the arithmetic", async ({ page }) => {
    await addToCart(page, DS1);
    await page.goto("/cart");

    const fret = page.getByRole("region", { name: "Fret & Valve" });
    await fret.getByRole("button", { name: "One more Boss DS-1 distortion pedal" }).click();

    // The line total and the shop's subtotal, both recomputed by the API.
    await expect(fret).toContainText(`${POUND}70.00`);
    await expect(page.getByRole("link", { name: "Cart, 2 items" })).toBeVisible();
  });

  test("asking for more than there are is refused in the API's words", async ({ page }) => {
    await addToCart(page, OM1);
    await page.goto("/cart");

    const northlight = page.getByRole("region", { name: "Northlight Analog" });
    await northlight.getByRole("button", { name: "One more Olympus OM-1 body, serviced" }).click();

    // Scoped to the shop's own section, which is also clear of Next's route
    // announcer - the empty role="alert" apps/web/CLAUDE.md warns about.
    await expect(northlight.getByRole("alert")).toBeVisible();
    await expect(northlight).toContainText(`${EURO}219.00`);
  });

  test("removing a shop's only line takes the shop with it, and the header follows", async ({
    page,
  }) => {
    await addToCart(page, OM1);
    await addToCart(page, DS1);
    await page.goto("/cart");

    await expect(page.getByRole("link", { name: "Cart, 2 items" })).toBeVisible();

    await page
      .getByRole("region", { name: "Northlight Analog" })
      .getByRole("button", { name: "Remove Olympus OM-1 body, serviced" })
      .click();

    await expect(page.getByRole("region", { name: "Northlight Analog" })).toHaveCount(0);
    await expect(page.getByRole("region", { name: "Fret & Valve" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Cart, 1 item" })).toBeVisible();
  });

  /**
   * PAGES in pages.spec.ts browses signed out, and /cart signed out is the
   * sign-in page. So a filled cart gets its phone-width and axe checks here.
   */
  test("a filled cart fits a phone and passes axe", async ({ page }) => {
    await addToCart(page, OM1);
    await addToCart(page, DS1);

    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto("/cart");
    await expect(page.getByRole("region", { name: "Fret & Valve" })).toBeVisible();

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow, "pixels of horizontal overflow").toBeLessThanOrEqual(0);

    const { violations } = await new AxeBuilder({ page }).analyze();
    expect(
      violations.map(
        (violation) => `${violation.id}: ${violation.help} (${violation.nodes.length})`,
      ),
    ).toEqual([]);
  });
});
