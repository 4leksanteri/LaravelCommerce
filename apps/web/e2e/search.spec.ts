import { expect, test } from "@playwright/test";

/**
 * Searching, against the demo catalogue (`make e2e` seeds it first).
 *
 * The assertions name demo listings on purpose. A search test that only
 * checked "some cards appeared" would pass against a search that ignored the
 * term entirely.
 */

test("a search from the header lands on results, and the box keeps the term", async ({ page }) => {
  await page.goto("/");

  const box = page.getByRole("searchbox", { name: "Search listings" });
  await box.fill("olympus");
  await box.press("Enter");

  await expect(page).toHaveURL(/\/search\?q=olympus$/);
  await expect(page.getByRole("heading", { level: 1 })).toContainText("olympus");
  await expect(page.getByRole("link", { name: "Olympus OM-1 body, serviced" })).toBeVisible();
  await expect(box).toHaveValue("olympus");
});

/**
 * "serviced" is in the OM-1's name and the Seamaster's description, which sit
 * in different categories - so choosing one has to remove the other.
 */
test("a category narrows the results and keeps the term", async ({ page }) => {
  await page.goto("/search?q=serviced");

  await expect(page.getByRole("link", { name: "Olympus OM-1 body, serviced" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Omega Seamaster, 1970s" })).toBeVisible();

  await page
    .getByRole("navigation", { name: "Filter by category" })
    .getByRole("link", { name: "Watches and clocks" })
    .click();

  await expect(page).toHaveURL(/\/search\?q=serviced&category=watches-and-clocks$/);
  await expect(page.getByRole("link", { name: "Omega Seamaster, 1970s" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Olympus OM-1 body, serviced" })).toHaveCount(0);
});

test("a one-letter search is refused with the API's reason, not an empty page", async ({
  page,
}) => {
  await page.goto("/search?q=a");

  await expect(page.getByRole("alert")).toContainText("at least two characters");
  await expect(page.getByRole("searchbox", { name: "Search listings" })).toHaveValue("a");
});

test("an unknown category says so, and offers the way out", async ({ page }) => {
  await page.goto("/search?q=lens&category=no-such-category");

  await expect(page.getByRole("alert")).toBeVisible();
  await expect(page.getByRole("link", { name: "Search all categories instead" })).toHaveAttribute(
    "href",
    "/search?q=lens",
  );
});

test("finding nothing says so plainly", async ({ page }) => {
  await page.goto("/search?q=zqxjvw");

  await expect(page.getByText(/Nothing matches/)).toBeVisible();
  await expect(page.getByRole("link", { name: "Browse everything" })).toBeVisible();
});
