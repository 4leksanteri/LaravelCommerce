import { expect, test } from "@playwright/test";

import { STAFF_SESSION } from "./support/session";

/**
 * A shop, as a place (ADR 0053).
 *
 * The page ADR 0028 left open, built on two endpoints that already existed. The
 * interesting assertions are the ones about what decides it exists at all: the
 * API's `scopePublic()`, which makes an unapproved shop, a suspended one and a
 * slug nobody used the same not-found page.
 */
const SHOP = "Northlight Analog";
const SLUG = "northlight-analog";
const LISTING = "Olympus OM-1 body, serviced";

test("a shop's page says what it sells", async ({ page }) => {
  await page.goto(`/shops/${SLUG}`);

  await expect(page.getByRole("heading", { level: 1 })).toHaveText(SHOP);

  // Its own listings, and how many there are.
  await expect(page.getByRole("link", { name: LISTING })).toBeVisible();
  await expect(page.getByText(/listings/)).toBeVisible();

  // What it charges in, before anybody looks at a price (ADR 0004).
  await expect(page.getByText(/Prices in EUR/)).toBeVisible();
});

test("a listing leads to the shop that sells it", async ({ page }) => {
  await page.goto(`/shops/${SLUG}/products/olympus-om-1-body-serviced`);

  // Text until ADR 0053, because there was nowhere for it to go.
  await page.getByRole("link", { name: SHOP }).click();

  await expect(page).toHaveURL(new RegExp(`/shops/${SLUG}$`));
  await expect(page.getByRole("heading", { level: 1 })).toHaveText(SHOP);
});

test("a shop nobody has heard of is the not-found page", async ({ page }) => {
  const response = await page.goto("/shops/no-such-shop-here");

  expect(response?.status()).toBe(404);
});

test.describe("a suspended shop", () => {
  test.use({ storageState: STAFF_SESSION });

  /**
   * ADR 0052's propagation, asserted from the storefront rather than from the
   * API: one enum case takes the shop's page with it, and nothing on that page
   * was written to know about suspensions.
   *
   * Kallio Keys, because no other spec touches it - and reinstated in a
   * `finally`, with `make seed-demo` as the net underneath that.
   */
  test("has no page while it is stopped", async ({ page }) => {
    const suspended = "Kallio Keys";

    try {
      await page.goto("/admin/shops?status=approved");

      const card = page.getByRole("listitem").filter({ hasText: suspended });
      await card.getByRole("button", { name: "Suspend this shop" }).click();

      const form = page.getByRole("form", { name: `Suspend ${suspended}` });
      await form
        .getByLabel("Why are you suspending it?")
        .fill("Checking that its page goes with it.");
      await form.getByRole("button", { name: "Suspend the shop" }).click();
      await expect(page.getByRole("listitem").filter({ hasText: suspended })).toHaveCount(0);

      expect((await page.goto("/shops/kallio-keys"))?.status(), "its page").toBe(404);
    } finally {
      await page.goto("/admin/shops?status=suspended");

      const card = page.getByRole("listitem").filter({ hasText: suspended });

      if ((await card.count()) > 0) {
        await card.getByRole("button", { name: "Let it trade again" }).click();
        await expect(page.getByRole("listitem").filter({ hasText: suspended })).toHaveCount(0);
      }
    }
  });
});
