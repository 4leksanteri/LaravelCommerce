import { expect, test, type Page } from "@playwright/test";

import { STAFF_SESSION } from "./support/session";

/**
 * Stopping a shop that is trading, and letting it start again (ADR 0052).
 *
 * **Retuned Audio, deliberately.** It is the one demo shop no other spec
 * touches: Second Hand Time underpins `support/session` and `support/orders`,
 * and Northlight Analog and Fret & Valve are asserted on by the cart, checkout,
 * product and pages specs. Suspending any of those would break the run rather
 * than test it.
 *
 * **Reinstated in a `finally`, and seeded back besides.** A suspended shop is
 * invisible, so a run that died here would leave the damage looking like a
 * listing that had simply gone missing. `make seed-demo` puts every demo shop
 * back to trading before each run for that reason, and this cleans up after
 * itself so the rest of the run sees the shop it expects.
 */
const SHOP = "Retuned Audio";
const SLUG = "retuned-audio";

/**
 * One of its listings, for the assertion that matters.
 *
 * **A shop has no page of its own**, only its listings do - so the shop's
 * public existence is read through the API, and the propagation to what
 * shoppers actually browse is read through a product page. The second is the
 * claim worth making in a browser: nothing in a suspension touches a listing,
 * and it goes anyway, through `Product::scopePublic()`.
 */
const LISTING = `/shops/${SLUG}/products/technics-sl-1200-mk2-turntable`;

/** The shop as the storefront sees it. Public, so no session is needed. */
const SHOP_RESOURCE = `/api/v1/shops/${SLUG}`;

async function reinstate(page: Page): Promise<void> {
  await page.goto("/admin/shops?status=suspended");

  const card = page.getByRole("listitem").filter({ hasText: SHOP });

  if ((await card.count()) > 0) {
    await card.getByRole("button", { name: "Let it trade again" }).click();
    await expect(page.getByRole("listitem").filter({ hasText: SHOP })).toHaveCount(0);
  }
}

test.describe("the demo reviewer", () => {
  test.use({ storageState: STAFF_SESSION });

  test("suspends a trading shop, and it leaves the storefront", async ({ page }) => {
    try {
      // Trading to begin with: it is on the storefront and its listings are on
      // sale.
      expect((await page.request.get(SHOP_RESOURCE)).status(), "before").toBe(200);
      expect((await page.goto(LISTING))?.status(), "its listing before").toBe(200);

      await page.goto("/admin/shops?status=approved");

      const card = page.getByRole("listitem").filter({ hasText: SHOP });
      await card.getByRole("button", { name: "Suspend this shop" }).click();

      const form = page.getByRole("form", { name: `Suspend ${SHOP}` });
      await form
        .getByLabel("Why are you suspending it?")
        .fill("Three disputes decided against it this month.");
      await form.getByRole("button", { name: "Suspend the shop" }).click();

      // It leaves the approved filter, and turns up under suspended.
      await expect(page.getByRole("listitem").filter({ hasText: SHOP })).toHaveCount(0);

      await page.goto("/admin/shops?status=suspended");
      await expect(page.getByRole("listitem").filter({ hasText: SHOP })).toBeVisible();

      /*
       * The whole point of the design: one enum case takes the shop off the
       * storefront, and its listings with it. Nothing in the suspension
       * touched a single product row.
       */
      expect((await page.request.get(SHOP_RESOURCE)).status(), "the shop").toBe(404);
      expect((await page.goto(LISTING))?.status(), "its listing").toBe(404);
    } finally {
      await reinstate(page);
    }
  });

  test("lets a suspended shop trade again", async ({ page }) => {
    try {
      await page.goto("/admin/shops?status=approved");

      const card = page.getByRole("listitem").filter({ hasText: SHOP });
      await card.getByRole("button", { name: "Suspend this shop" }).click();

      const form = page.getByRole("form", { name: `Suspend ${SHOP}` });
      await form.getByLabel("Why are you suspending it?").fill("Suspended to be reinstated.");
      await form.getByRole("button", { name: "Suspend the shop" }).click();
      await expect(page.getByRole("listitem").filter({ hasText: SHOP })).toHaveCount(0);

      await reinstate(page);

      // Back on the storefront, exactly as it was, listings included.
      expect((await page.request.get(SHOP_RESOURCE)).status(), "the shop").toBe(200);
      expect((await page.goto(LISTING))?.status(), "its listing").toBe(200);
    } finally {
      await reinstate(page);
    }
  });

  /** The shop reads this, so the API refuses one that says nothing useful. */
  test("refuses a reason that says nothing", async ({ page }) => {
    await page.goto("/admin/shops?status=approved");

    const card = page.getByRole("listitem").filter({ hasText: SHOP });
    await card.getByRole("button", { name: "Suspend this shop" }).click();

    const form = page.getByRole("form", { name: `Suspend ${SHOP}` });
    await form.getByLabel("Why are you suspending it?").fill("no");
    await form.getByRole("button", { name: "Suspend the shop" }).click();

    await expect(form.getByText(/at least 10 characters/i)).toBeVisible();

    // And nothing happened to the shop.
    expect((await page.request.get(SHOP_RESOURCE)).status()).toBe(200);
  });
});
