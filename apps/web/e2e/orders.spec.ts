import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

import { finishOrder, placeOrder } from "./support/orders";
import { apiCall, asSeller, emptyCart, SHOPPER_SESSION } from "./support/session";

/**
 * A buyer's orders: the list, one order's page, and the three things a buyer
 * can do to an order - against the demo catalogue, as the demo shopper.
 *
 * Orders are placed through the API rather than the checkout form, which
 * checkout.spec covers. They buy the Seiko 5 on its canvas strap: three in
 * stock, and nothing else in the suite wants it. That matters because one test
 * here completes its order, and a completed order keeps its stock for good.
 * `make seed-demo` puts it back before every run.
 */
const SEIKO = {
  shop: "second-hand-time",
  product: "seiko-5-automatic-snk809",
  variant: "Canvas strap",
};

test("your orders need somebody signed in", async ({ page }) => {
  await page.goto("/orders");

  await expect(page).toHaveURL(/\/login\?next=%2Forders$/);
});

test.describe("signed in as the demo shopper", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test.beforeEach(async ({ page }) => {
    await emptyCart(page);
  });

  test("a new order is listed, opens, and can be cancelled before the shop accepts it", async ({
    page,
    browser,
  }) => {
    const reference = await placeOrder(page, SEIKO);

    try {
      await page.goto("/orders");

      const row = page.getByRole("listitem").filter({ hasText: reference });
      await expect(row).toContainText("Seiko 5 automatic, SNK809");
      await expect(row).toContainText("Waiting for the shop to accept it");

      await row.getByRole("link").click();
      await expect(page).toHaveURL(new RegExp(`/orders/${reference}$`));
      await expect(page.getByRole("heading", { level: 1 })).toHaveText(`Order ${reference}`);
      await expect(page.getByText("Waiting for Second Hand Time to accept it.")).toBeVisible();

      await page.getByRole("button", { name: "Cancel order" }).click();
      await page.getByRole("button", { name: "Yes, cancel it" }).click();

      await expect(page.getByRole("main").getByText("Cancelled", { exact: true })).toBeVisible();
      await expect(page.getByText("Nothing more will happen to this order.")).toBeVisible();
      await expect(page.getByRole("button", { name: "Cancel order" })).toHaveCount(0);
    } finally {
      await finishOrder(page, browser, reference);
    }
  });

  test("an order the shop has sent: more time, then confirming it arrived", async ({
    page,
    browser,
  }) => {
    const reference = await placeOrder(page, SEIKO);

    try {
      // The shop's side, which no buyer can do.
      await asSeller(browser, async (shop) => {
        for (const step of ["acceptance", "shipment"]) {
          const response = await apiCall(shop, "POST", `/seller/orders/${reference}/${step}`);
          expect(response.ok(), `the shop's ${step}`).toBe(true);
        }
      });

      await page.goto(`/orders/${reference}`);
      await expect(page.getByRole("main").getByText("Sent", { exact: true })).toBeVisible();

      const deadline = page.getByText(/If you do not, it completes on its own on/);
      await expect(deadline).toBeVisible();
      const before = (await deadline.textContent()) ?? "";

      // Not a dispute: more time, capped, and the new date is the API's.
      await expect(page.getByText("2 extensions left")).toBeVisible();
      await page.getByRole("button", { name: "It has not arrived yet" }).click();
      await expect(
        page.getByText(/^Given more time: it now completes on its own on/),
      ).toBeVisible();
      await expect(page.getByText("1 extension left")).toBeVisible();
      await expect(deadline).not.toHaveText(before);

      await page.getByRole("button", { name: "Confirm it arrived" }).click();
      await page.getByRole("button", { name: "Yes, it arrived" }).click();

      await expect(page.getByRole("main").getByText("Completed", { exact: true })).toBeVisible();
      await expect(page.getByRole("button", { name: "Confirm it arrived" })).toHaveCount(0);
      await expect(page.getByRole("button", { name: "It has not arrived yet" })).toHaveCount(0);
    } finally {
      await finishOrder(page, browser, reference);
    }
  });

  /**
   * The API answers 404 alike for a reference that is somebody else's and one
   * that was never issued (its own tests prove the first); the page draws the
   * same not-found for both, and says nothing about which.
   */
  test("a reference that is not one of yours is not found", async ({ page }) => {
    const response = await page.goto("/orders/ZZZZZZZZZZ");

    expect(response?.status()).toBe(404);
    await expect(
      page.getByRole("heading", { name: "Nothing lives at this address." }),
    ).toBeVisible();
  });

  test("the orders pages fit a phone and pass axe", async ({ page, browser }) => {
    const reference = await placeOrder(page, SEIKO);

    try {
      await page.setViewportSize({ width: 375, height: 812 });

      for (const path of ["/orders", `/orders/${reference}`]) {
        await page.goto(path);
        await expect(page.getByRole("heading", { level: 1 })).toBeVisible();

        const overflow = await page.evaluate(
          () => document.documentElement.scrollWidth - window.innerWidth,
        );
        expect(overflow, `${path}: pixels of horizontal overflow`).toBeLessThanOrEqual(0);

        const { violations } = await new AxeBuilder({ page }).analyze();
        expect(
          violations.map(
            (violation) => `${violation.id}: ${violation.help} (${violation.nodes.length})`,
          ),
          path,
        ).toEqual([]);
      }
    } finally {
      await finishOrder(page, browser, reference);
    }
  });
});
