import { expect, test, type Browser, type Page } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { finishOrder, placeOrder, SEIKO } from "./support/orders";
import { apiCall, asSeller, emptyCart, SHOPPER_SESSION } from "./support/session";

const LISTING = `/shops/${SEIKO.shop}/products/${SEIKO.product}`;

/**
 * Leaving a review on something you received (ADR 0047).
 *
 * The whole rule is the fixture: a review needs a **completed** order, so this
 * places one, has the shop accept and send it, and confirms it arrived before
 * the form exists at all. Nothing else in the suite reaches that state.
 *
 * **Written to run twice.** `make seed-demo` puts the Seiko's stock back before
 * every run, but a review is not demo data and stays: the demo shopper has one
 * from the previous run, and the API refuses a second (409). So this leaves one
 * or changes the one already there, which exercises both verbs honestly rather
 * than assuming a clean slate.
 *
 * What a listing nobody has reviewed draws is `ReviewList`'s own test. It needs
 * a listing this suite never completes an order for, and asserting against demo
 * data that happens to stay untouched is a test that breaks when the catalogue
 * changes for unrelated reasons.
 */
async function receive(page: Page, browser: Browser): Promise<string> {
  await emptyCart(page);

  const reference = await placeOrder(page, SEIKO);

  await asSeller(browser, async (shop) => {
    for (const step of ["acceptance", "shipment"]) {
      const response = await apiCall(shop, "POST", `/seller/orders/${reference}/${step}`);
      expect(response.ok(), `the shop's ${step}`).toBe(true);
    }
  });

  const confirmed = await apiCall(page, "POST", `/orders/${reference}/completion`);
  expect(confirmed.ok(), "confirming it arrived").toBe(true);

  return reference;
}

test.describe("the demo shopper", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("reviews something they received, and it appears on the listing", async ({
    page,
    browser,
  }) => {
    const reference = await receive(page, browser);

    try {
      await page.goto(LISTING);

      // Drawn from `can_review`, which is the API's answer about an order
      // history the browser cannot see.
      const form = page.getByRole("form", { name: /review/i });
      await expect(form).toBeVisible();

      await form.getByRole("radio", { name: "4" }).check();
      await form.getByLabel(/What you thought/).fill("Keeps time within ten seconds a day.");

      // Leaving one on the first run, changing it on every run after.
      const leave = form.getByRole("button", { name: "Leave review" });
      const save = form.getByRole("button", { name: "Save changes" });

      await ((await leave.count()) > 0 ? leave : save).click();

      await expect(form.getByText("Saved.")).toBeVisible();

      await expect(
        page
          .getByRole("list", { name: "Reviews" })
          .getByText("Keeps time within ten seconds a day."),
      ).toBeVisible();
    } finally {
      // A no-op for an order that completed, and the safety net for a run that
      // failed before it did - an order left pending holds its stock.
      await finishOrder(page, browser, reference);
    }
  });

  test("the listing with its reviews fits a phone and passes axe", async ({ page, browser }) => {
    const reference = await receive(page, browser);

    try {
      await expectFitsAPhoneAndPassesAxe(page, LISTING);
    } finally {
      await finishOrder(page, browser, reference);
    }
  });
});
