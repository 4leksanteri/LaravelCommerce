import path from "node:path";

import { expect, test, type APIResponse, type Page } from "@playwright/test";

/**
 * The demo shopper, and the session the setup project saves for it.
 *
 * **One account for the suite, signed in once.** Registration is limited to ten
 * an hour per IP and signing in to five a minute per address. Those are the
 * production limits, and a suite that registered an account per test hit them
 * on its second run of the hour, failing in ways that looked like application
 * bugs. The suite works within them rather than being given looser ones.
 *
 * The account is made by `make seed-demo`, which `make e2e` runs first. The
 * password is the seeder's `DemoCatalogueSeeder::PASSWORD`, repeated here
 * because a test cannot import PHP.
 */
export const SHOPPER = {
  email: "demo-shopper@example.test",
  password: "demo-password-2026",
} as const;

/**
 * Where the signed-in session is saved. It holds a live session cookie, so the
 * directory is gitignored and never committed.
 */
export const SHOPPER_SESSION = path.resolve(__dirname, "../.auth/shopper.json");

/**
 * A write to the API as the signed-in shopper, through the proxy - for
 * arranging a test or tidying up after one, never for the thing under test.
 *
 * It sends what the browser would: the CSRF token read from the session's
 * cookie, and an Origin, which Sanctum decides statefulness by (ADR 0002).
 */
export async function apiCall(
  page: Page,
  method: "POST" | "PATCH" | "DELETE",
  path: string,
  data?: unknown,
): Promise<APIResponse> {
  const baseURL = test.info().project.use.baseURL;
  const token = (await page.context().cookies()).find((cookie) => cookie.name === "XSRF-TOKEN");

  if (!baseURL || !token) {
    throw new Error("No signed-in session to call the API with. Did the setup project run?");
  }

  return page.request.fetch(`/api/v1${path}`, {
    method,
    data,
    headers: {
      accept: "application/json",
      "x-xsrf-token": decodeURIComponent(token.value),
      origin: baseURL,
      referer: `${baseURL}/`,
    },
  });
}

/**
 * Start from an empty basket.
 *
 * Every signed-in test shares the one shopper, so each empties the cart first
 * rather than inheriting whatever the last one left.
 */
export async function emptyCart(page: Page): Promise<void> {
  const response = await apiCall(page, "DELETE", "/cart");

  expect(response.status(), "emptying the cart before the test").toBe(200);
}
