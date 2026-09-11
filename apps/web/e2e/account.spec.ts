import { expect, test } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { finishOrder, placeOrder, SEIKO } from "./support/orders";
import { emptyCart, SHOPPER_SESSION } from "./support/session";

/**
 * The account area: its overview, and the sidebar it shares with the shop's
 * pages (ADR 0033). The orders pages inside it are orders.spec's.
 */
test("your account needs somebody signed in", async ({ page }) => {
  await page.goto("/account");

  await expect(page).toHaveURL(/\/login\?next=%2Faccount$/);
});

test.describe("signed in as the demo shopper", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test.beforeEach(async ({ page }) => {
    await emptyCart(page);
  });

  test("the header leads to the account", async ({ page }) => {
    await page.goto("/");
    await page.getByRole("link", { name: "Your account" }).click();

    await expect(page).toHaveURL(/\/account$/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Your account");
  });

  test("the overview shows the latest orders, and the sidebar leads to the rest", async ({
    page,
    browser,
  }) => {
    const reference = await placeOrder(page, SEIKO);

    try {
      await page.goto("/account");
      await expect(page.getByText("Signed in as demo-shopper@example.test")).toBeVisible();

      // Newest first, so the order just placed leads the list.
      const latest = page.getByRole("list", { name: "Latest orders" });
      await expect(latest.getByRole("listitem").first()).toContainText(reference);

      const account = page.getByRole("navigation", { name: "Your account" });
      await expect(account.getByRole("link", { name: "Overview" })).toHaveAttribute(
        "aria-current",
        "page",
      );

      await account.getByRole("link", { name: "Orders" }).click();
      await expect(page).toHaveURL(/\/account\/orders$/);
      await expect(account.getByRole("link", { name: "Orders" })).toHaveAttribute(
        "aria-current",
        "page",
      );
      await expect(account.getByRole("link", { name: "Overview" })).not.toHaveAttribute(
        "aria-current",
      );

      // No shop yet, so the shop's half of the sidebar is a way to open one.
      await expect(page.getByRole("main").getByRole("link", { name: "Open a shop" })).toBeVisible();
    } finally {
      await finishOrder(page, browser, reference);
    }
  });

  test("the overview fits a phone and passes axe", async ({ page }) => {
    await expectFitsAPhoneAndPassesAxe(page, "/account");
  });
});
