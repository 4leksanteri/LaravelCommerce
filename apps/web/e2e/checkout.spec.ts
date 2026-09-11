import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

import { apiCall, emptyCart, SHOPPER_SESSION } from "./support/session";

/**
 * Checkout, against the demo catalogue, as the demo shopper.
 *
 * **This test places real orders, and real orders take stock.** It buys the
 * DS-1 (four in stock) and the Zuiko (two) rather than the OM-1, whose single
 * unit the product and cart specs depend on, and it cancels every order it
 * places through the buyer's own cancellation - which gives the stock back
 * exactly as a person cancelling would. `make seed-demo` also puts demo stock
 * back each run, as a net for a run that fails before it can tidy up.
 */

const DS1 = "/shops/fret-and-valve/products/boss-ds-1-distortion-pedal";
const ZUIKO = "/shops/northlight-analog/products/zuiko-50mm-f14-lens";

// Escapes rather than the symbols, which root CLAUDE.md section 15 keeps out.
const EURO = "\u20ac";
const POUND = "\u00a3";

async function addToCart(page: Page, path: string) {
  await page.goto(path);
  await page.getByRole("button", { name: "Add to cart" }).click();
  await expect(page.getByText("Added to your cart.")).toBeVisible();
}

test("checkout needs somebody signed in", async ({ page }) => {
  await page.goto("/checkout");

  await expect(page).toHaveURL(/\/login\?next=%2Fcheckout$/);
});

test.describe("signed in as the demo shopper", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test.beforeEach(async ({ page }) => {
    await emptyCart(page);
  });

  test("an empty basket has nothing to check out", async ({ page }) => {
    await page.goto("/checkout");

    await expect(page.getByText("There is nothing to check out.")).toBeVisible();
    await expect(page.getByRole("button", { name: "Place orders" })).toHaveCount(0);
  });

  test("a basket becomes one order per shop, and the confirmation survives a reload", async ({
    page,
  }) => {
    await addToCart(page, DS1);
    await addToCart(page, ZUIKO);

    await page.goto("/cart");
    await page.getByRole("link", { name: "Continue to checkout" }).click();
    await expect(page).toHaveURL(/\/checkout$/);

    // An address of this run's own, through the real form. The shopper's book
    // keeps every earlier run's too, so the street is unique to this one.
    const street = `Testikatu ${Date.now()}`;
    const add = page.getByRole("button", { name: "Add a new address" });

    if (await add.count()) {
      await add.click();
    }

    const form = page.getByRole("form", { name: "New address" });
    await form.getByLabel("Recipient").fill("Demo Shopper");
    await form.getByLabel("Address", { exact: true }).fill(street);
    await form.getByLabel("City").fill("Helsinki");
    await form.getByLabel("Country").fill("FI");
    await form.getByRole("button", { name: "Save address" }).click();

    await expect(page.getByRole("radio", { name: new RegExp(street) })).toBeChecked();

    await page.getByRole("button", { name: "Place orders" }).click();
    await expect(page).toHaveURL(/\/checkout\/placed\?orders=/);

    const placed = page.getByRole("list", { name: "Orders placed" });
    const references = await placed.locator("code").allTextContents();

    try {
      await expect(placed.getByRole("listitem").filter({ has: page.locator("code") })).toHaveCount(
        2,
      );
      await expect(placed).toContainText("Fret & Valve");
      await expect(placed).toContainText(`${POUND}35.00`);
      await expect(placed).toContainText("Northlight Analog");
      await expect(placed).toContainText(`${EURO}129.00`);
      await expect(page.getByText(street)).toBeVisible();

      // The basket is empty now, so the header's count has gone.
      await expect(page.getByRole("link", { name: "Cart", exact: true })).toBeVisible();

      // An address of its own, so a reload keeps it.
      await page.reload();
      await expect(placed.locator("code")).toHaveCount(2);
    } finally {
      // Give the stock back, the way a buyer would.
      for (const reference of references) {
        const response = await apiCall(page, "POST", `/orders/${reference}/cancellation`);
        expect(response.ok(), `cancelling ${reference}`).toBe(true);
      }
    }
  });

  test("the checkout page fits a phone and passes axe", async ({ page }) => {
    await addToCart(page, DS1);

    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto("/checkout");
    await expect(page.getByRole("heading", { name: "Deliver to" })).toBeVisible();

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
