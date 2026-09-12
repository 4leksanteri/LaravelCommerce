import { expect, test } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { messageTo } from "./support/mailpit";
import { SHOPPER_SESSION, STAFF_SESSION } from "./support/session";

/**
 * The review queue (ADR 0037): who may see it, and the two decisions.
 *
 * The two applications are seeded pending by `make seed-demo`, which `make e2e`
 * runs first and which puts them back on every run - a decision cannot be
 * undone, so a run needs its own to decide on.
 */
const TO_APPROVE = "Bench and Bellows";
const TO_TURN_DOWN = "Copper Kettle Audio";
const TURNED_DOWN_CONTACT = "demo-hopeful-two@example.test";

test("the review queue needs somebody signed in", async ({ page }) => {
  await page.goto("/admin/shops");

  await expect(page).toHaveURL(/\/login\?next=%2Fadmin%2Fshops$/);
});

test.describe("an account that is not staff", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("is told what the page is, and sees no applications", async ({ page }) => {
    await page.goto("/admin/shops");

    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Shops to review");
    await expect(page.getByText(/This account is not one/)).toBeVisible();
    await expect(page.getByRole("listitem").filter({ hasText: TO_APPROVE })).toHaveCount(0);

    // The header offers the page only to staff.
    await expect(page.getByRole("link", { name: "Review shops" })).toHaveCount(0);
  });
});

test.describe("the demo reviewer", () => {
  test.use({ storageState: STAFF_SESSION });

  test("reaches the queue from the header", async ({ page }) => {
    await page.goto("/");

    await page.getByRole("link", { name: "Review shops" }).click();

    await expect(page).toHaveURL(/\/admin\/shops$/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Shops to review");
  });

  test("approves an application, and it leaves the queue as an open shop", async ({ page }) => {
    await page.goto("/admin/shops?status=pending");

    const application = page.getByRole("listitem").filter({ hasText: TO_APPROVE });
    await expect(application).toContainText("Awaiting review");

    await application.getByRole("button", { name: "Approve" }).click();
    await application.getByRole("button", { name: "Yes, let it trade" }).click();

    // Decided, so it is no longer waiting on anybody.
    await expect(page.getByRole("listitem").filter({ hasText: TO_APPROVE })).toHaveCount(0);

    await page.goto("/admin/shops?status=approved");
    await expect(page.getByRole("listitem").filter({ hasText: TO_APPROVE })).toContainText("Open");
  });

  test("turns one down with a reason, and the applicant is told", async ({ page }) => {
    const reason = "The contact address bounces. Apply again with one that reaches you.";

    await page.goto("/admin/shops?status=pending");

    await page
      .getByRole("listitem")
      .filter({ hasText: TO_TURN_DOWN })
      .getByRole("button", { name: "Turn it down" })
      .click();

    // The reason is required, and asking for it is the pause before a decision
    // that cannot be undone.
    const form = page.getByRole("form", { name: `Turn down ${TO_TURN_DOWN}` });
    await form.getByRole("button", { name: "Send the decision" }).click();
    await expect(form.getByText(/reason field is required/)).toBeVisible();

    await form.getByLabel("Why are you turning it down?").fill(reason);
    await form.getByRole("button", { name: "Send the decision" }).click();

    await expect(page.getByRole("listitem").filter({ hasText: TO_TURN_DOWN })).toHaveCount(0);

    // The decision keeps the reason, which is what the applicant was sent.
    await page.goto("/admin/shops?status=rejected");
    await expect(page.getByRole("listitem").filter({ hasText: TO_TURN_DOWN })).toContainText(
      reason,
    );

    await messageTo(TURNED_DOWN_CONTACT, "Your shop application was not approved");
  });

  test("narrows the queue to where each shop stands", async ({ page }) => {
    await page.goto("/admin/shops");

    const filters = page.getByRole("navigation", { name: "Filter by status" });
    await filters.getByRole("link", { name: "Open" }).click();

    await expect(page).toHaveURL(/\/admin\/shops\?status=approved$/);
    await expect(filters.getByRole("link", { name: "Open" })).toHaveAttribute(
      "aria-current",
      "true",
    );
    await expect(page.getByRole("listitem").filter({ hasText: "Second Hand Time" })).toBeVisible();

    await filters.getByRole("link", { name: "Not approved" }).click();
    await expect(page).toHaveURL(/\/admin\/shops\?status=rejected$/);
    await expect(page.getByRole("listitem").filter({ hasText: "Second Hand Time" })).toHaveCount(0);
  });

  test("a status that does not exist falls back to the whole queue", async ({ page }) => {
    await page.goto("/admin/shops?status=lost");

    await expect(page).toHaveURL(/\/admin\/shops$/);
  });

  test("the queue fits a phone and passes axe", async ({ page }) => {
    await expectFitsAPhoneAndPassesAxe(page, "/admin/shops");
  });
});
