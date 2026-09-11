import { expect, test } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { apiCall, APPLICANT_SESSION, SELLER_SESSION, SHOPPER_SESSION } from "./support/session";

/**
 * The shop's side of the account area: applying, the shop's overview, and its
 * settings (ADR 0033).
 *
 * Three accounts, because what these pages show depends on where the shop
 * stands:
 *
 *   the shopper     has no shop, and is sent to open one
 *   the applicant   has none either, and applies; `make seed-demo` takes the
 *                   application away again before the next run
 *   the seller      runs Second Hand Time, which is open
 */
test("the shop's pages need somebody signed in", async ({ page }) => {
  await page.goto("/seller");

  await expect(page).toHaveURL(/\/login\?next=%2Fseller$/);
});

test.describe("an account with no shop", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("is sent to open one", async ({ page }) => {
    await page.goto("/seller");

    await expect(page).toHaveURL(/\/sell$/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Open a shop");
  });

  test("the application fits a phone and passes axe", async ({ page }) => {
    await expectFitsAPhoneAndPassesAxe(page, "/sell");
  });
});

test.describe("the demo applicant", () => {
  test.use({ storageState: APPLICANT_SESSION });

  test("applies, lands on the shop's page, and the header follows", async ({ page }) => {
    const name = `E2E Camera Repairs ${Date.now()}`;

    await page.goto("/sell");

    const form = page.getByRole("form", { name: "Shop application" });
    await form.getByLabel("Shop name").fill(name);
    await form
      .getByLabel("About your shop")
      .fill("Serviced film cameras, each tested with a roll before it goes.");
    await form.getByLabel("Currency").selectOption("EUR");
    await form.getByRole("button", { name: "Send application" }).click();

    await expect(page).toHaveURL(/\/seller$/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(name);
    await expect(page.getByRole("main").getByText("Awaiting review").first()).toBeVisible();

    // A full page load, so the header was drawn for an account with a shop.
    await expect(page.getByRole("link", { name: "Your shop" })).toBeVisible();

    // There is nothing more to apply for, so the application sends it back.
    await page.goto("/sell");
    await expect(page).toHaveURL(/\/seller$/);
  });
});

test.describe("the owner of an open shop", () => {
  test.use({ storageState: SELLER_SESSION });

  test("sees where the shop stands and what it has", async ({ page }) => {
    await page.goto("/seller");

    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Second Hand Time");
    await expect(page.getByText(/^Your shop is open\./)).toBeVisible();
    // The figures' own labels. "Orders" alone would also find the sidebar's
    // link to the account's orders.
    await expect(page.getByRole("term").filter({ hasText: /^Listings$/ })).toBeVisible();
    await expect(page.getByRole("term").filter({ hasText: /^Orders$/ })).toBeVisible();

    const shop = page.getByRole("navigation", { name: "Your shop" });
    await expect(shop.getByRole("link", { name: "Overview" })).toHaveAttribute(
      "aria-current",
      "page",
    );
  });

  test("changes what the shop says about itself, and the overview shows it", async ({ page }) => {
    await page.goto("/seller/settings");

    const form = page.getByRole("form", { name: "Shop details" });
    const about = form.getByLabel("About your shop");
    const original = await about.inputValue();
    const changed = `Serviced watches, with the paperwork. Checked ${Date.now()}.`;

    try {
      await about.fill(changed);
      await form.getByRole("button", { name: "Save" }).click();
      await expect(form.getByText("Saved.")).toBeVisible();

      await page
        .getByRole("navigation", { name: "Your shop" })
        .getByRole("link", { name: "Overview" })
        .click();
      await expect(page).toHaveURL(/\/seller$/);
      await expect(page.getByText(changed)).toBeVisible();
    } finally {
      const restored = await apiCall(page, "PATCH", "/seller", { description: original });
      expect(restored.ok(), "putting the description back").toBe(true);
    }
  });

  test("the shop's pages fit a phone and pass axe", async ({ page }) => {
    await expectFitsAPhoneAndPassesAxe(page, "/seller");
    await expectFitsAPhoneAndPassesAxe(page, "/seller/settings");
  });
});
