import { expect, test, type Page } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { apiCall, asShopper, SHOPPER_SESSION, STAFF_SESSION } from "./support/session";

/**
 * Reporting something, and the platform deciding about it (ADR 0054).
 *
 * **Nothing here takes a listing down, deliberately.** A takedown is sticky by
 * design - `PublishProduct` refuses a removed listing and a CHECK constraint
 * enforces it - so upholding a report against a demo listing in a browser would
 * remove it from the catalogue for the rest of the run, and land on whichever
 * unrelated spec navigates to it next as "reading the listing" failing.
 * `DemoCatalogueSeeder::reinstate()` is the net under that, and this suite still
 * does not lean on it: the one thing upheld here is a **review**, whose hiding
 * is visible on the same page and costs the catalogue nothing.
 *
 * What a browser is needed for is the round trip - a shopper reports, staff see
 * what was reported and why, and the decision reaches the page - which no
 * single suite on either side can see on its own. The takedown itself is
 * PHPUnit's, where it can be asserted against the database.
 */
const DS1 = { shop: "fret-and-valve", product: "boss-ds-1-distortion-pedal" };

/**
 * A second listing, and it has to be a second one.
 *
 * There is **one open report per person per thing** (a partial unique index),
 * so the demo shopper cannot report the DS-1 through the form here and again
 * through the API below - the second would be the 409 that rule exists for.
 * Reporting two different listings is not merely allowed, it is ordinary.
 */
const BIG_MUFF = { shop: "fret-and-valve", product: "electro-harmonix-big-muff-pi" };

async function firstReviewId(page: Page): Promise<number> {
  const response = await apiCall(page, "GET", `/shops/${DS1.shop}/products/${DS1.product}/reviews`);
  expect(response.ok(), "reading the reviews").toBe(true);

  const { data } = (await response.json()) as { data: { id: number }[] };
  const first = data[0];

  if (!first) {
    throw new Error(`${DS1.product} has no reviews to report. Has make seed-demo run?`);
  }

  return first.id;
}

test("the moderation queue needs somebody signed in", async ({ page }) => {
  await page.goto("/admin/reports");

  await expect(page).toHaveURL(/\/login\?next=%2Fadmin%2Freports$/);
});

test.describe("an account that is not staff", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("is told what the page is, and is offered no link to it", async ({ page }) => {
    await page.goto("/admin/reports");

    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Reports");
    await expect(page.getByText(/This account is not one/)).toBeVisible();

    // The header offers the page only to staff, and from its own answer.
    await expect(page.getByRole("link", { name: "Reports" })).toHaveCount(0);
  });

  /**
   * The promise the control makes: a report is filed and **nothing happens to
   * the listing**. Somebody who reported a rival's listing and watched it
   * vanish would have found a delete button.
   */
  test("reports a listing, and it stays on sale", async ({ page }) => {
    await page.goto(`/shops/${BIG_MUFF.shop}/products/${BIG_MUFF.product}`);

    await page.getByRole("button", { name: "Report this listing" }).click();

    const form = page.getByRole("form", { name: "Report this listing" });
    await form.getByLabel("What is wrong with it?").selectOption("counterfeit");
    await form
      .getByLabel(/Anything to add/)
      .fill("The photographs are lifted from another shop's listing.");
    await form.getByRole("button", { name: "Report it" }).click();

    await expect(page.getByText(/it stays on sale until they do/)).toBeVisible();

    // Still on the storefront: the page renders as a listing rather than as
    // not-found, which is what a takedown would have made it.
    await page.reload();
    await expect(page.getByRole("heading", { level: 1 })).toContainText("Big Muff");
  });
});

test.describe("the demo reviewer", () => {
  test.use({ storageState: STAFF_SESSION });

  test("reads what was reported, and leaves it up", async ({ page, browser }) => {
    await asShopper(browser, async (shopper) => {
      const filed = await apiCall(
        shopper,
        "POST",
        `/shops/${DS1.shop}/products/${DS1.product}/reports`,
        { reason: "spam", note: "This pedal is listed four times by the same shop." },
      );

      expect(filed.status(), "filing the report").toBe(201);
    });

    await page.goto("/admin/reports");

    const card = page.getByRole("listitem").filter({ hasText: "Boss DS-1" });
    await expect(card).toBeVisible();

    // What was said, and why - staff cannot otherwise see either.
    await expect(card).toContainText("Spam, or posted over and over");
    await expect(card).toContainText("This pedal is listed four times");

    await card.getByRole("button", { name: "Leave it up" }).click();

    const deciding = page.getByRole("form", { name: "Leave it up" });
    await deciding
      .getByLabel("Why is it staying up?")
      .fill("One listing, and the others are different pedals from the same range.");
    await deciding.getByRole("button", { name: "Leave it up" }).click();

    // A decided report leaves the queue.
    await expect(page.getByRole("listitem").filter({ hasText: "Boss DS-1" })).toHaveCount(0);
  });

  /**
   * Upholding one, and the only thing this suite takes down.
   *
   * A hidden review is put back by `DemoCatalogueSeeder::reinstate()` before the
   * next run, and hiding one costs the catalogue nothing meanwhile.
   */
  test("hides a review it upholds a report about", async ({ page, browser }) => {
    const reviewId = await asShopper(browser, async (shopper) => {
      const id = await firstReviewId(shopper);

      const filed = await apiCall(
        shopper,
        "POST",
        `/shops/${DS1.shop}/products/${DS1.product}/reviews/${id}/reports`,
        { reason: "abusive", note: "It is about the seller rather than about the pedal." },
      );

      expect(filed.status(), "reporting the review").toBe(201);

      return id;
    });

    await page.goto("/admin/reports");

    const card = page.getByRole("listitem").filter({ hasText: "out of 5" });
    await expect(card).toBeVisible();

    await card.getByRole("button", { name: "Take it down" }).click();

    const deciding = page.getByRole("form", { name: "Take it down" });
    await deciding
      .getByLabel("Why are you taking it down?")
      .fill("It is about the seller rather than about what was bought.");
    await deciding.getByRole("button", { name: "Take it down" }).click();

    await expect(page.getByRole("listitem").filter({ hasText: "out of 5" })).toHaveCount(0);

    // Gone from the listing's own page, which is the whole point of hiding it.
    await asShopper(browser, async (shopper) => {
      const reviews = await apiCall(
        shopper,
        "GET",
        `/shops/${DS1.shop}/products/${DS1.product}/reviews`,
      );

      const { data } = (await reviews.json()) as { data: { id: number }[] };

      expect(data.some((review) => review.id === reviewId)).toBe(false);
    });
  });

  test("the queue fits a phone and passes axe", async ({ page }) => {
    await expectFitsAPhoneAndPassesAxe(page, "/admin/reports");
  });
});
