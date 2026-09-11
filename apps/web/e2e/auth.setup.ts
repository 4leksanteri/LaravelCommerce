import { expect, test as setup, type Page } from "@playwright/test";

import {
  APPLICANT,
  APPLICANT_SESSION,
  SELLER,
  SELLER_SESSION,
  SHOPPER,
  SHOPPER_SESSION,
} from "./support/session";

/**
 * Signs in once as each demo account the suite uses, through the real form,
 * and saves each session for every test that needs it.
 *
 * The only sign-ins the suite spends on the shared accounts: three a run,
 * inside the limits of five a minute per address and twenty per IP. See
 * support/session.ts for why it is one each and not one per test.
 */
async function signIn(page: Page, account: { email: string; password: string }, session: string) {
  await page.goto("/login");
  await page.getByLabel("Email").fill(account.email);
  await page.getByLabel("Password").fill(account.password);
  await page.getByRole("button", { name: "Sign in" }).click();

  await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();

  await page.context().storageState({ path: session });
}

setup("sign in as the demo shopper, once for the whole run", async ({ page }) => {
  await signIn(page, SHOPPER, SHOPPER_SESSION);
});

setup("sign in as a demo shop owner, once for the whole run", async ({ page }) => {
  await signIn(page, SELLER, SELLER_SESSION);
});

setup("sign in as the demo applicant, once for the whole run", async ({ page }) => {
  await signIn(page, APPLICANT, APPLICANT_SESSION);
});
