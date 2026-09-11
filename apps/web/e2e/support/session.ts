import path from "node:path";

import { expect, test, type APIResponse, type Browser, type Page } from "@playwright/test";

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
 * The owner of one of the demo shops, Second Hand Time.
 *
 * For moving an order along from the shop's side - accepting it, sending it -
 * which a test about the buyer's side needs and a buyer cannot do. Signed in
 * once by the setup project, like the shopper, and for the same reason.
 */
export const SELLER = {
  email: "demo-second-hand-time@example.test",
  password: SHOPPER.password,
} as const;

export const SELLER_SESSION = path.resolve(__dirname, "../.auth/seller.json");

/**
 * An account with a confirmed address and no shop. `make seed-demo` takes its
 * shop away again on every run, so the suite can apply to sell with it once a
 * run without registering anybody (ADR 0033).
 */
export const APPLICANT = {
  email: "demo-applicant@example.test",
  password: SHOPPER.password,
} as const;

export const APPLICANT_SESSION = path.resolve(__dirname, "../.auth/applicant.json");

/**
 * An account whose address and password one test changes. Changing a password
 * signs out every other session (ADR 0034), so it cannot be done to an account
 * whose saved session other tests depend on. `make seed-demo` gives this one
 * its password and a confirmed address back on every run, and it has no saved
 * session: the test signs in itself.
 */
export const SETTINGS_TESTER = {
  email: "demo-settings@example.test",
  password: SHOPPER.password,
} as const;

/** Signs in through the real form, as a person would. */
export async function signIn(page: Page, account: { email: string; password: string }) {
  await page.goto("/login");
  await page.getByLabel("Email").fill(account.email);
  await page.getByLabel("Password").fill(account.password);
  await page.getByRole("button", { name: "Sign in" }).click();

  await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();
}

/**
 * Runs `work` with a page signed in as the demo shop owner, in a browser
 * context of its own, so the shopper's session in the test's own page is left
 * exactly as it was.
 */
export async function asSeller<T>(browser: Browser, work: (page: Page) => Promise<T>): Promise<T> {
  const context = await browser.newContext({
    storageState: SELLER_SESSION,
    baseURL: test.info().project.use.baseURL,
  });

  try {
    return await work(await context.newPage());
  } finally {
    await context.close();
  }
}

/**
 * The same for the demo shopper: for a spec whose own page is the shop's, when
 * an order has to be placed, read or finished from the buyer's side.
 */
export async function asShopper<T>(browser: Browser, work: (page: Page) => Promise<T>): Promise<T> {
  const context = await browser.newContext({
    storageState: SHOPPER_SESSION,
    baseURL: test.info().project.use.baseURL,
  });

  try {
    return await work(await context.newPage());
  } finally {
    await context.close();
  }
}

/**
 * A call to the API as whoever the page is signed in as, through the proxy -
 * for arranging a test or tidying up after one, never for the thing under test.
 *
 * It sends what the browser would: the CSRF token read from the session's
 * cookie, and an Origin, which Sanctum decides statefulness by (ADR 0002).
 * A read needs the Origin too, or its session cookie is ignored.
 */
export async function apiCall(
  page: Page,
  method: "GET" | "POST" | "PATCH" | "DELETE",
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
