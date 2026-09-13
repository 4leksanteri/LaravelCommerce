import { expect, test, type Browser } from "@playwright/test";

import { messageTo } from "./support/mailpit";
import { finishOrder, placeOrder, SEIKO } from "./support/orders";
import { asShopper, emptyCart, SELLER_SESSION, SHOPPER } from "./support/session";

/**
 * The two sides of an order talking to each other (ADR 0050).
 *
 * One conversation per order, read at two different addresses - the shop's
 * under `/seller/orders` and the buyer's under `/account/orders` - so the whole
 * point of this spec is that it is the *same* thread seen from both ends. Two
 * suites each seeing half of it would not prove that.
 *
 * The order is placed through the API in the shopper's own context, as the
 * other order specs do, and finished in a `finally` so a failed run does not
 * leave stock held.
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

test.describe("the owner of Second Hand Time", () => {
  test.use({ storageState: SELLER_SESSION });

  test("writes to the buyer, who reads it and replies", async ({ page, browser }) => {
    const reference = await anOrder(browser);

    try {
      await page.goto(`/seller/orders/${reference}`);

      // Nothing said yet, which is a sentence rather than an empty box.
      await expect(page.getByText("Nothing has been said about this order yet.")).toBeVisible();

      const shopForm = page.getByRole("form", { name: "Send a message" });
      await shopForm.getByLabel("Message").fill("Posting this afternoon, with tracking.");
      await shopForm.getByRole("button", { name: "Send" }).click();

      const sent = page.getByRole("listitem").filter({ hasText: "Posting this afternoon" });
      await expect(sent).toBeVisible();

      // Written by whoever is reading, so the shop's own message says "You".
      await expect(sent).toContainText("You");

      await messageTo(SHOPPER.email, `sent you a message about order ${reference}`);

      await asShopper(browser, async (shopper) => {
        await shopper.goto(`/account/orders/${reference}`);

        // The same message, from the other end, under the shop's name.
        const received = shopper
          .getByRole("listitem")
          .filter({ hasText: "Posting this afternoon" });
        await expect(received).toBeVisible();
        await expect(received).toContainText("Second Hand Time");

        const buyerForm = shopper.getByRole("form", { name: "Send a message" });
        await buyerForm.getByLabel("Message").fill("Thank you, there is no rush.");
        await buyerForm.getByRole("button", { name: "Send" }).click();

        await expect(
          shopper.getByRole("listitem").filter({ hasText: "there is no rush" }),
        ).toBeVisible();
      });

      // And the shop has the reply on its own copy of the order.
      await page.reload();
      await expect(
        page.getByRole("listitem").filter({ hasText: "there is no rush" }),
      ).toBeVisible();
    } finally {
      await finish(browser, reference);
    }
  });

  /**
   * The decision the domain rests on: a cancelled order is exactly when two
   * people still need to reach each other, so nothing takes the form away.
   */
  test("can still be written to after the order is called off", async ({ page, browser }) => {
    const reference = await anOrder(browser);

    try {
      await page.goto(`/seller/orders/${reference}`);
      await page.getByRole("button", { name: "Cancel order" }).click();

      const cancellation = page.getByRole("form", { name: "Cancel the order" });
      await cancellation.getByLabel("Why are you cancelling?").fill("The last one sold today.");
      await cancellation.getByRole("button", { name: "Cancel the order" }).click();
      await expect(page.getByText(/You cancelled it/)).toBeVisible();

      const form = page.getByRole("form", { name: "Send a message" });
      await form.getByLabel("Message").fill("Sorry about that - a refund is on its way.");
      await form.getByRole("button", { name: "Send" }).click();

      await expect(
        page.getByRole("listitem").filter({ hasText: "a refund is on its way" }),
      ).toBeVisible();
    } finally {
      await finish(browser, reference);
    }
  });
});
