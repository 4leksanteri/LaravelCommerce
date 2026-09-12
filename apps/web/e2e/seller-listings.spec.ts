import { expect, test, type Page } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { apiCall, SELLER_SESSION } from "./support/session";

/**
 * A shop's catalogue (ADR 0038): writing a listing up, changing how it is
 * sold, a photograph, and putting it on sale.
 *
 * Everything is created inside the test and deleted in a `finally`, because
 * this runs against the demo shop that other specs buy from - a stray listing
 * would be in the storefront's results on the next run.
 *
 * The photograph is a real one-pixel PNG built here rather than a fixture
 * file: the API decodes what it is sent and stores a WebP, so the bytes have
 * to be a decodable image, and a committed binary would be the only one in the
 * repository.
 */
const PIXEL = Buffer.from(
  "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==",
  "base64",
);

/** A listing straight through the API, for tests that are about a later step. */
async function aListing(page: Page, name: string): Promise<number> {
  const response = await apiCall(page, "POST", "/seller/products", {
    name,
    description: "Written by an end-to-end test.",
    variants: [{ name: "Default", price_minor: 1000, stock: 1 }],
  });

  expect(response.status(), "creating a listing").toBe(201);

  return ((await response.json()) as { data: { id: number } }).data.id;
}

async function remove(page: Page, id: number): Promise<void> {
  const response = await apiCall(page, "DELETE", `/seller/products/${id}`);

  expect(response.ok(), `deleting listing ${id}`).toBe(true);
}

test("the catalogue needs somebody signed in", async ({ page }) => {
  await page.goto("/seller/listings");

  await expect(page).toHaveURL(/\/login\?next=%2Fseller%2Flistings$/);
});

test.describe("the owner of Second Hand Time", () => {
  test.use({ storageState: SELLER_SESSION });

  test("writes a listing up, adds an option and a photograph, and puts it on sale", async ({
    page,
  }) => {
    const name = `E2E Pocket Watch ${Date.now()}`;
    let id: number | null = null;

    try {
      await page.goto("/seller/listings");
      await page.getByRole("link", { name: "New listing" }).click();

      const form = page.getByRole("form", { name: "New listing" });
      await form.getByLabel("Name").fill(name);
      await form.getByLabel("Description").fill("A test listing, gone by the end of the run.");
      await form.getByLabel("Category").selectOption({ index: 1 });
      await form.getByLabel("Option").fill("Steel case");
      await form.getByLabel("Price in EUR").fill("249.50");
      await form.getByLabel("In stock").fill("2");
      await form.getByRole("button", { name: "Save as a draft" }).click();

      await expect(page).toHaveURL(/\/seller\/listings\/\d+$/);
      id = Number(/\/listings\/(\d+)$/.exec(page.url())?.[1]);

      await expect(page.getByRole("heading", { level: 1 })).toHaveText(name);
      await expect(page.getByRole("main").getByText("Draft", { exact: true })).toBeVisible();
      // Typed as 249.50 and stored as minor units.
      await expect(page.getByText(/\u20ac249\.50, 2 in stock/)).toBeVisible();

      // Another way to buy the same thing.
      await page.getByRole("button", { name: "Add an option" }).click();
      const option = page.getByRole("form", { name: "Add an option" });
      await option.getByLabel("Option").fill("Gold case");
      await option.getByLabel("Price in EUR").fill("399");
      await option.getByLabel("In stock").fill("1");
      await option.getByRole("button", { name: "Add the option" }).click();
      await expect(page.getByText(/\u20ac399\.00, 1 in stock/)).toBeVisible();

      await page
        .getByLabel("Add a photograph")
        .setInputFiles({ name: "watch.png", mimeType: "image/png", buffer: PIXEL });
      await expect(page.getByRole("list", { name: "Photographs" }).getByRole("img")).toHaveCount(1);

      await page.getByRole("button", { name: "Put it on sale" }).click();
      await expect(page.getByRole("main").getByText("On sale", { exact: true })).toBeVisible();

      // On sale in an approved shop is what a shopper can actually see.
      await page.getByRole("link", { name: "See it as a shopper does" }).click();
      await expect(page.getByRole("heading", { level: 1 })).toHaveText(name);

      await page.goBack();
      await page.getByRole("button", { name: "Take it off sale" }).click();
      await expect(page.getByRole("main").getByText("Draft", { exact: true })).toBeVisible();
    } finally {
      if (id) {
        await remove(page, id);
      }
    }
  });

  /** A price the page cannot read never becomes a request (ADR 0038). */
  test("refuses a price it cannot read", async ({ page }) => {
    await page.goto("/seller/listings/new");

    const form = page.getByRole("form", { name: "New listing" });
    await form.getByLabel("Name").fill("E2E unreadable price");
    await form.getByLabel("Price in EUR").fill("about a tenner");
    await form.getByRole("button", { name: "Save as a draft" }).click();

    await expect(form.getByText(/Write the price as a plain amount in EUR/)).toBeVisible();
    await expect(page).toHaveURL(/\/seller\/listings\/new$/);
  });

  test("narrows the catalogue to drafts and to what is on sale", async ({ page }) => {
    const name = `E2E Draft ${Date.now()}`;
    const id = await aListing(page, name);

    try {
      await page.goto("/seller/listings");

      const filters = page.getByRole("navigation", { name: "Filter by status" });
      await filters.getByRole("link", { name: "Draft" }).click();

      await expect(page).toHaveURL(/\/seller\/listings\?status=draft$/);
      await expect(page.getByRole("listitem").filter({ hasText: name })).toBeVisible();

      await filters.getByRole("link", { name: "On sale" }).click();
      await expect(page).toHaveURL(/\/seller\/listings\?status=published$/);
      await expect(page.getByRole("listitem").filter({ hasText: name })).toHaveCount(0);
    } finally {
      await remove(page, id);
    }
  });

  test("deletes a listing after asking", async ({ page }) => {
    const name = `E2E Doomed ${Date.now()}`;
    const id = await aListing(page, name);

    await page.goto(`/seller/listings/${id}`);
    await page.getByRole("button", { name: "Delete this listing" }).click();
    await page.getByRole("button", { name: "Yes, delete it" }).click();

    await expect(page).toHaveURL(/\/seller\/listings$/);
    await expect(page.getByRole("listitem").filter({ hasText: name })).toHaveCount(0);
  });

  test("a listing that is not this shop's is not found", async ({ page }) => {
    const response = await page.goto("/seller/listings/999999");

    expect(response?.status()).toBe(404);
  });

  test("the catalogue pages fit a phone and pass axe", async ({ page }) => {
    const id = await aListing(page, `E2E Axe ${Date.now()}`);

    try {
      await expectFitsAPhoneAndPassesAxe(page, "/seller/listings");
      await expectFitsAPhoneAndPassesAxe(page, "/seller/listings/new");
      await expectFitsAPhoneAndPassesAxe(page, `/seller/listings/${id}`);
    } finally {
      await remove(page, id);
    }
  });
});
