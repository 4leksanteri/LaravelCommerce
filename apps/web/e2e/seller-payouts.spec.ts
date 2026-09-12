import { expect, test } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { SELLER_SESSION, SHOPPER_SESSION } from "./support/session";

/**
 * The payouts page (ADR 0039).
 *
 * **Nothing here opens an account.** Every write on this page reaches Stripe:
 * opening one creates a real connected account on the platform's test-mode
 * account, and a suite that ran it would leave one behind on every run, or have
 * to delete it and hope. So this covers what the page draws before any of that
 * happens, and the writes are covered where they can be without a network -
 * PHPUnit against FakeStripe, and Vitest for what each form sends.
 *
 * The demo shop is approved and has no payout account, which is the state that
 * matters most: the one a seller meets first.
 */
test("the payouts page needs somebody signed in", async ({ page }) => {
  await page.goto("/seller/payouts");

  await expect(page).toHaveURL(/\/login\?next=%2Fseller%2Fpayouts$/);
});

test.describe("an account with no shop", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("is sent to open one", async ({ page }) => {
    await page.goto("/seller/payouts");

    await expect(page).toHaveURL(/\/sell$/);
  });
});

test.describe("the owner of Second Hand Time", () => {
  test.use({ storageState: SELLER_SESSION });

  test("is offered an account to open, with the countries the API allows", async ({ page }) => {
    await page.goto("/seller/payouts");

    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Payouts");
    await expect(page.getByRole("main").getByText("Not set up", { exact: true })).toBeVisible();

    const form = page.getByRole("form", { name: "Open a payout account" });
    await expect(form.getByLabel("Where you live")).toBeVisible();
    await expect(form.getByRole("option", { name: "Finland" })).toBeAttached();
    await expect(form.getByRole("checkbox")).not.toBeChecked();

    // Deliberately not submitted: that call reaches Stripe.
    await expect(form.getByRole("button", { name: "Open the account" })).toBeVisible();
  });

  test("is reachable from the shop's sidebar", async ({ page }) => {
    await page.goto("/seller");

    await page
      .getByRole("navigation", { name: "Your shop" })
      .getByRole("link", { name: "Payouts" })
      .click();

    await expect(page).toHaveURL(/\/seller\/payouts$/);
  });

  test("fits a phone and passes axe", async ({ page }) => {
    await expectFitsAPhoneAndPassesAxe(page, "/seller/payouts");
  });
});
