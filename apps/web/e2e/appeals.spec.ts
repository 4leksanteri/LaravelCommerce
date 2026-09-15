import { expect, test, type Browser, type Page } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { signIn, SHOPPER, SHOPPER_SESSION, STAFF_SESSION } from "./support/session";

/**
 * Answering back about a decision the platform took, and the platform looking
 * again (ADR 0059).
 *
 * **Retuned Audio, for the reason `suspension.spec` picks it**: it is the one
 * demo shop no other spec asserts on, so stopping it for the length of a test
 * breaks nothing else in the run. This suite goes further than that one and
 * puts it back *through the feature under test* - upholding the appeal is what
 * reinstates the shop - with a hook behind it in case the test dies first.
 *
 * **The round trip is the whole point, and no single suite can see it.** A shop
 * is stopped by staff, its owner argues in a browser, staff read that argument
 * beside their own reason and reverse themselves, and the shop is back on the
 * storefront. PHPUnit owns each half; only this sees them meet.
 *
 * The owner signs in here rather than in `auth.setup`, because no other spec
 * needs this account: one sign-in a run, inside the five-a-minute limit.
 */
const SHOP = "Retuned Audio";
const SLUG = "retuned-audio";

/** Its owner, made by `make seed-demo` as `demo-{slug}@example.test`. */
const OWNER = { email: `demo-${SLUG}@example.test`, password: SHOPPER.password } as const;

/** The shop as the storefront sees it. Public, so no session is needed. */
const SHOP_RESOURCE = `/api/v1/shops/${SLUG}`;

const WHY_STOPPED = "Three disputes decided against it this month.";
const THE_ARGUMENT = "All three disputes were one order, and the buyer was refunded in full.";

/**
 * Runs `work` as the shop's owner, in a context of its own.
 *
 * **The empty `storageState` is load-bearing, not defensive.** A context opened
 * inside a describe that carries `test.use({ storageState })` picks that
 * session up, so a `newContext` given only a `baseURL` arrives signed in as
 * staff. `/login` then does exactly what it is written to do - send somebody
 * already signed in where they were going - and the form never renders, so
 * filling "Email" waits for an element that will never exist until the test
 * times out. `asSeller` and `asShopper` pass a session for the same reason;
 * this one has to pass the absence of one.
 */
async function asOwner<T>(browser: Browser, work: (page: Page) => Promise<T>): Promise<T> {
  const context = await browser.newContext({
    baseURL: test.info().project.use.baseURL,
    storageState: { cookies: [], origins: [] },
  });

  try {
    const page = await context.newPage();
    await signIn(page, OWNER);

    return await work(page);
  } finally {
    await context.close();
  }
}

async function reinstate(page: Page): Promise<void> {
  await page.goto("/admin/shops?status=suspended");

  const card = page.getByRole("listitem").filter({ hasText: SHOP });

  if ((await card.count()) > 0) {
    await card.getByRole("button", { name: "Let it trade again" }).click();
    await expect(page.getByRole("listitem").filter({ hasText: SHOP })).toHaveCount(0);
  }
}

test("the appeals queue needs somebody signed in", async ({ page }) => {
  await page.goto("/admin/appeals");

  await expect(page).toHaveURL(/\/login\?next=%2Fadmin%2Fappeals$/);
});

test.describe("an account that is not staff", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("is told what the page is, and is offered no link to it", async ({ page }) => {
    await page.goto("/admin/appeals");

    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Appeals");
    await expect(page.getByText(/This account is not one/)).toBeVisible();

    // The header offers the page only to staff, and from its own answer.
    await expect(page.getByRole("link", { name: "Appeals" })).toHaveCount(0);
  });
});

test.describe("the demo reviewer", () => {
  test.use({ storageState: STAFF_SESSION });

  /*
   * A hook rather than a `finally` inside the test, because a `finally` does
   * not survive a timeout. The run this spec was written in timed out
   * mid-test, that block never ran, and the shop stayed suspended - which
   * failed `suspension.spec` and three of `categories.spec` rather than only
   * this one. A suspended shop is invisible, so the damage reads as listings
   * that have simply gone missing.
   *
   * It runs after the phone check too, where it finds nothing and returns.
   */
  test.afterEach(async ({ page }) => {
    await reinstate(page);
  });

  test("a suspended shop argues, and the platform reverses itself", async ({ page, browser }) => {
    // Trading to begin with, so the reinstatement at the end means something.
    expect((await page.request.get(SHOP_RESOURCE)).status(), "before").toBe(200);

    await page.goto("/admin/shops?status=approved");

    const trading = page.getByRole("listitem").filter({ hasText: SHOP });
    await trading.getByRole("button", { name: "Suspend this shop" }).click();

    const suspending = page.getByRole("form", { name: `Suspend ${SHOP}` });
    await suspending.getByLabel("Why are you suspending it?").fill(WHY_STOPPED);
    await suspending.getByRole("button", { name: "Suspend the shop" }).click();

    await expect(page.getByRole("listitem").filter({ hasText: SHOP })).toHaveCount(0);

    await asOwner(browser, async (owner) => {
      await owner.goto("/seller");

      // The reason is on the page, which is what an appeal argues against.
      await expect(owner.getByText(WHY_STOPPED)).toBeVisible();

      await owner.getByRole("button", { name: "Appeal the suspension" }).click();

      const form = owner.getByRole("form", { name: "Appeal the suspension" });
      await form.getByLabel("Why was this decision wrong?").fill(THE_ARGUMENT);
      await form.getByRole("button", { name: "Send the appeal" }).click();

      // Said, and it says a second look is coming - never that the suspension
      // has lifted.
      await expect(owner.getByText(/You have appealed/)).toBeVisible();
      await expect(owner.getByText(/Your shop stays suspended until they do/)).toBeVisible();

      /*
       * The reason `has_open_appeal` exists, asserted on a cold load.
       * `can_appeal` is false now, so a page holding only that field would
       * show this seller the suspension, no form and no sign their argument
       * had arrived.
       */
      await owner.reload();
      await expect(owner.getByText(/You have appealed/)).toBeVisible();
      await expect(owner.getByRole("button", { name: "Appeal the suspension" })).toHaveCount(0);
    });

    await page.goto("/admin/appeals");

    const appeal = page.getByRole("listitem").filter({ hasText: SHOP });
    await expect(appeal).toBeVisible();

    // Both sides of the argument: the platform's own words, and theirs.
    await expect(appeal).toContainText(WHY_STOPPED);
    await expect(appeal).toContainText(THE_ARGUMENT);

    await appeal.getByRole("button", { name: "Uphold the appeal" }).click();

    const deciding = page.getByRole("form", { name: "Uphold the appeal" });
    await deciding
      .getByLabel("Why is the decision being reversed?")
      .fill("One order rather than three, and it was refunded. The suspension was wrong.");
    await deciding.getByRole("button", { name: "Uphold the appeal" }).click();

    // A decided appeal leaves the queue.
    await expect(page.getByRole("listitem").filter({ hasText: SHOP })).toHaveCount(0);

    // And the shop is back on the storefront, which is the whole remedy.
    expect((await page.request.get(SHOP_RESOURCE)).status(), "after").toBe(200);
  });

  test("the queue fits a phone and passes axe", async ({ page }) => {
    await expectFitsAPhoneAndPassesAxe(page, "/admin/appeals");
  });
});
