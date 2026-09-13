import { expect, test, type Browser, type Page } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { finishOrder, placeOrder, SEIKO } from "./support/orders";
import {
  apiCall,
  asSeller,
  asShopper,
  emptyCart,
  SHOPPER_SESSION,
  STAFF_SESSION,
} from "./support/session";

/**
 * What happens when the two sides disagree about what arrived (ADR 0051).
 *
 * A dispute exists only while the money is held, so the fixture is the whole
 * setup: the order has to be **shipped** and paid for before the form exists at
 * all. That is reviews.spec's `receive` stopping one step earlier.
 *
 * **Deciding one ends the order**, so each run consumes a unit of the Seiko for
 * good - a cancelled shipment does not give stock back (ADR 0011), and
 * `make seed-demo` puts it back before the next run.
 */
async function shippedOrder(page: Page, browser: Browser): Promise<string> {
  await emptyCart(page);

  const reference = await placeOrder(page, SEIKO);

  await asSeller(browser, async (shop) => {
    for (const step of ["acceptance", "shipment"]) {
      const response = await apiCall(shop, "POST", `/seller/orders/${reference}/${step}`);
      expect(response.ok(), `the shop's ${step}`).toBe(true);
    }
  });

  return reference;
}

test("the dispute queue needs somebody signed in", async ({ page }) => {
  await page.goto("/admin/disputes");

  await expect(page).toHaveURL(/\/login\?next=%2Fadmin%2Fdisputes$/);
});

test.describe("an account that is not staff", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("is told what the page is, and is offered no link to it", async ({ page }) => {
    await page.goto("/admin/disputes");

    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Disputes");
    await expect(page.getByText(/This account is not one/)).toBeVisible();

    // The header offers the page only to staff, and from its own answer.
    await expect(page.getByRole("link", { name: "Disputes" })).toHaveCount(0);
  });

  /**
   * The decision the domain rests on: the window is exactly as wide as the
   * money is held, so an order nobody has sent cannot be disputed.
   */
  test("cannot dispute an order that has not been sent", async ({ page, browser }) => {
    await emptyCart(page);
    const reference = await placeOrder(page, SEIKO);

    try {
      await page.goto(`/account/orders/${reference}`);

      await expect(page.getByRole("button", { name: "Something went wrong" })).toHaveCount(0);
    } finally {
      await finishOrder(page, browser, reference);
    }
  });
});

test.describe("the demo reviewer", () => {
  test.use({ storageState: STAFF_SESSION });

  test("decides a dispute, and the buyer reads the decision", async ({ page, browser }) => {
    const reference = await asShopper(browser, async (shopper) => {
      const placed = await shippedOrder(shopper, browser);

      await shopper.goto(`/account/orders/${placed}`);
      await shopper.getByRole("button", { name: "Something went wrong" }).click();

      const raising = shopper.getByRole("form", { name: "Tell us what went wrong" });
      await raising
        .getByLabel("What went wrong?")
        .fill("It never arrived, and tracking has not moved in two weeks.");
      await raising.getByRole("button", { name: "Raise a dispute" }).click();

      // Held, and said so: the order will not complete on its own meanwhile.
      await expect(shopper.getByText(/we are looking at it/i)).toBeVisible();
      await expect(shopper.getByText(/payment is held until we have decided/)).toBeVisible();

      return placed;
    });

    try {
      await page.goto("/admin/disputes");

      const card = page.getByRole("listitem").filter({ hasText: reference });
      await expect(card).toBeVisible();
      await expect(card).toContainText("Demo shopper");
      await expect(card).toContainText("Second Hand Time");

      await card.getByRole("button", { name: "Refund the buyer" }).click();

      const deciding = page.getByRole("form", { name: `Refund the buyer for order ${reference}` });
      await deciding
        .getByLabel("Why are you refunding it?")
        .fill("The carrier never scanned it, so we are making the buyer whole.");
      await deciding.getByRole("button", { name: "Refund the buyer" }).click();

      // A decided dispute leaves the queue: it is read on its order instead.
      await expect(page.getByRole("listitem").filter({ hasText: reference })).toHaveCount(0);

      await asShopper(browser, async (shopper) => {
        await shopper.goto(`/account/orders/${reference}`);

        await expect(shopper.getByText(/Decided in your favour/)).toBeVisible();
        await expect(shopper.getByText(/The carrier never scanned it/)).toBeVisible();
      });
    } finally {
      await asShopper(browser, (shopper) => finishOrder(shopper, browser, reference));
    }
  });

  test("the queue fits a phone and passes axe", async ({ page }) => {
    await expectFitsAPhoneAndPassesAxe(page, "/admin/disputes");
  });
});
