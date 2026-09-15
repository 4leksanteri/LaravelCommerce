import { expect, test } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { SHOPPER_SESSION, STAFF_SESSION } from "./support/session";

/**
 * What the platform has decided about one shop (ADR 0060).
 *
 * **This spec only reads.** The round trip that writes rows - suspend, appeal,
 * uphold - is already driven by `appeals.spec`, and a second suite that stopped
 * a shop would risk the cascade that one already cost: a suspended shop is
 * invisible, so a run that died mid-test failed four tests in two unrelated
 * specs. Reading a record changes nothing, so this one is safe beside anything.
 *
 * It asserts what the page promises rather than a count. The table is
 * append-only and `make seed-demo` does not clear it, so rows accumulate across
 * runs and any exact number here would be wrong by the second one.
 */
const SHOP = "Second Hand Time";

test("a shop record needs somebody signed in", async ({ page }) => {
  await page.goto("/admin/shops/1");

  await expect(page).toHaveURL(/\/login\?next=%2Fadmin%2Fshops%2F1$/);
});

test.describe("an account that is not staff", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("is told what the page is", async ({ page }) => {
    await page.goto("/admin/shops/1");

    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Shop record");
    await expect(page.getByText(/This account is not one/)).toBeVisible();
  });
});

test.describe("the demo reviewer", () => {
  test.use({ storageState: STAFF_SESSION });

  /**
   * Reached from the card the suspension buttons are on, which is where
   * "has this happened before" is actually asked.
   */
  test("reaches a shop's record from the queue it would suspend it on", async ({ page }) => {
    await page.goto("/admin/shops?status=approved");

    const card = page.getByRole("listitem").filter({ hasText: SHOP });
    await card.getByRole("link", { name: "What has been decided about it" }).click();

    await expect(page).toHaveURL(/\/admin\/shops\/\d+$/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(SHOP);

    // Either it has a record or it says it has none. Both are the page working.
    const decisions = page.getByRole("list", { name: "Decisions" });
    const nothing = page.getByText(/never decided anything about this shop/);

    await expect(decisions.or(nothing).first()).toBeVisible();

    // And back to where it was reached from.
    await page.getByRole("link", { name: "Shops to review" }).click();
    await expect(page).toHaveURL(/\/admin\/shops$/);
  });

  test("the record fits a phone and passes axe", async ({ page }) => {
    await page.goto("/admin/shops?status=approved");

    await page
      .getByRole("listitem")
      .filter({ hasText: SHOP })
      .getByRole("link", { name: "What has been decided about it" })
      .click();

    await expectFitsAPhoneAndPassesAxe(page, new URL(page.url()).pathname);
  });
});
