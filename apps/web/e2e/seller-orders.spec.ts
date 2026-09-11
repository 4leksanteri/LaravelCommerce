import { expect, test, type Browser } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { messageTo } from "./support/mailpit";
import { finishOrder, placeOrder, SEIKO } from "./support/orders";
import { asShopper, emptyCart, SELLER_SESSION, SHOPPER } from "./support/session";

/**
 * A shop's orders, from the shop's side (ADR 0036): the queue, one order, and
 * accepting, sending and cancelling it.
 *
 * The page under test is the owner of Second Hand Time's. The orders come from
 * the demo shopper, placed through the API in a context of their own, and each
 * is finished from the buyer's side in a `finally`, as the buyer's specs do.
 */
async function anOrder(browser: Browser): Promise<string> {
  return asShopper(browser, async (shopper) => {
    await emptyCart(shopper);

    return placeOrder(shopper, SEIKO);
  });
}

async function finish(browser: Browser, reference: string): Promise<void> {
  await asShopper(browser, (shopper) => finishOrder(shopper, browser, reference));
}

test("the shop's orders need somebody signed in", async ({ page }) => {
  await page.goto("/seller/orders");

  await expect(page).toHaveURL(/\/login\?next=%2Fseller%2Forders$/);
});

test.describe("the owner of Second Hand Time", () => {
  test.use({ storageState: SELLER_SESSION });

  test("accepts an order and marks it sent, and the buyer is told", async ({ page, browser }) => {
    const reference = await anOrder(browser);

    try {
      await page.goto("/seller/orders?status=pending");

      const row = page.getByRole("listitem").filter({ hasText: reference });
      await expect(row).toContainText("To accept");
      await expect(row).toContainText("Demo shopper");

      await row.getByRole("link").click();
      await expect(page).toHaveURL(new RegExp(`/seller/orders/${reference}$`));
      await expect(page.getByRole("heading", { name: "Send to" })).toBeVisible();
      await expect(page.getByText("Waiting for you to accept it.")).toBeVisible();

      await page.getByRole("button", { name: "Accept order" }).click();
      await expect(page.getByRole("main").getByText("To send", { exact: true })).toBeVisible();
      await expect(page.getByText("Waiting for you to send it.")).toBeVisible();

      await page.getByRole("button", { name: "Mark as sent" }).click();
      await expect(page.getByRole("main").getByText("Sent", { exact: true })).toBeVisible();
      await expect(page.getByText(/^Waiting for Demo shopper to confirm it arrived/)).toBeVisible();
      await expect(page.getByRole("button", { name: "Mark as sent" })).toHaveCount(0);

      await messageTo(SHOPPER.email, `sent order ${reference}`);
    } finally {
      await finish(browser, reference);
    }
  });

  /**
   * The reason is required, and the form that collects it is the pause before
   * the cancellation. The buyer then reads it on their side.
   */
  test("cancels an order, saying why, and the buyer reads the reason", async ({
    page,
    browser,
  }) => {
    const reference = await anOrder(browser);
    const reason = "The last one sold in the shop this morning.";

    try {
      await page.goto(`/seller/orders/${reference}`);
      await page.getByRole("button", { name: "Cancel order" }).click();

      const form = page.getByRole("form", { name: "Cancel the order" });
      await form.getByRole("button", { name: "Cancel the order" }).click();
      await expect(form.getByText(/reason field is required/)).toBeVisible();

      await form.getByLabel("Why are you cancelling?").fill(reason);
      await form.getByRole("button", { name: "Cancel the order" }).click();
      await expect(page.getByText(`You cancelled it. Your reason: ${reason}`)).toBeVisible();

      await asShopper(browser, async (shopper) => {
        await shopper.goto(`/account/orders/${reference}`);
        await expect(
          shopper.getByText(`Second Hand Time cancelled it. Their reason: ${reason}`),
        ).toBeVisible();
      });
    } finally {
      await finish(browser, reference);
    }
  });

  test("narrows the queue to where each order stands", async ({ page, browser }) => {
    const reference = await anOrder(browser);

    try {
      await page.goto("/seller/orders");

      const filters = page.getByRole("navigation", { name: "Filter by status" });
      await filters.getByRole("link", { name: "To accept" }).click();

      await expect(page).toHaveURL(/\/seller\/orders\?status=pending$/);
      await expect(filters.getByRole("link", { name: "To accept" })).toHaveAttribute(
        "aria-current",
        "true",
      );
      await expect(page.getByRole("listitem").filter({ hasText: reference })).toBeVisible();

      await filters.getByRole("link", { name: "Completed" }).click();
      await expect(page).toHaveURL(/\/seller\/orders\?status=completed$/);
      await expect(page.getByRole("listitem").filter({ hasText: reference })).toHaveCount(0);
    } finally {
      await finish(browser, reference);
    }
  });

  test("an order that is not this shop's is not found", async ({ page }) => {
    const response = await page.goto("/seller/orders/ZZZZZZZZZZ");

    expect(response?.status()).toBe(404);
  });

  test("the orders pages fit a phone and pass axe", async ({ page, browser }) => {
    const reference = await anOrder(browser);

    try {
      await expectFitsAPhoneAndPassesAxe(page, "/seller/orders");
      await expectFitsAPhoneAndPassesAxe(page, `/seller/orders/${reference}`);
    } finally {
      await finish(browser, reference);
    }
  });
});
