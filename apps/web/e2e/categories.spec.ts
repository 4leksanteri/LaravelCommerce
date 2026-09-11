import { expect, test } from "@playwright/test";

/**
 * Browsing by category, against the demo catalogue (`make e2e` seeds it).
 *
 * Named listings again, for the reason search.spec.ts gives: "some cards
 * appeared" would pass against a page that ignored the category.
 */

test("a category lists everything filed under it, subcategories included", async ({ page }) => {
  await page.goto("/categories/audio");

  await expect(page.getByRole("heading", { level: 1, name: "Audio" })).toBeVisible();
  // Filed under Turntables and Headphones, not under Audio itself.
  await expect(page.getByRole("link", { name: "Technics SL-1200 MK2 turntable" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Sony WH-1000XM4 headphones, boxed" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Olympus OM-1 body, serviced" })).toHaveCount(0);
});

test("a subcategory narrows it, and the breadcrumb leads back up", async ({ page }) => {
  await page.goto("/categories/audio");

  await page
    .getByRole("navigation", { name: "Subcategories" })
    .getByRole("link", { name: "Headphones" })
    .click();

  await expect(page).toHaveURL(/\/categories\/headphones$/);
  await expect(page.getByRole("link", { name: "Sony WH-1000XM4 headphones, boxed" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Technics SL-1200 MK2 turntable" })).toHaveCount(0);

  await page
    .getByRole("navigation", { name: "Breadcrumb" })
    .getByRole("link", { name: "Audio" })
    .click();

  await expect(page).toHaveURL(/\/categories\/audio$/);
});

test("searching within a category hands over to search with the category kept", async ({
  page,
}) => {
  await page.goto("/categories/audio");

  const box = page.getByRole("searchbox", { name: "Search in Audio" });
  await box.fill("boxed");
  await box.press("Enter");

  await expect(page).toHaveURL(/\/search\?q=boxed&category=audio$/);
  await expect(page.getByRole("link", { name: "Sony WH-1000XM4 headphones, boxed" })).toBeVisible();
});

test("a category with nothing in it says so", async ({ page }) => {
  await page.goto("/categories/bicycles");

  await expect(page.getByText("Nothing is listed in Bicycles yet.")).toBeVisible();
});

test("a category that does not exist is the not-found page", async ({ page }) => {
  const response = await page.goto("/categories/no-such-category");

  expect(response?.status()).toBe(404);
  await expect(page.getByRole("heading", { name: "Nothing lives at this address." })).toBeVisible();
});
