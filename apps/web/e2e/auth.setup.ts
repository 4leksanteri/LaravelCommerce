import { expect, test as setup } from "@playwright/test";

import { SHOPPER, SHOPPER_SESSION } from "./support/session";

/**
 * Signs in as the demo shopper once, through the real form, and saves the
 * session for every test that needs somebody signed in.
 *
 * The one sign-in the suite spends on the shared account. See support/session.ts
 * for why it is one and not one per test.
 */
setup("sign in as the demo shopper, once for the whole run", async ({ page }) => {
  await page.goto("/login");
  await page.getByLabel("Email").fill(SHOPPER.email);
  await page.getByLabel("Password").fill(SHOPPER.password);
  await page.getByRole("button", { name: "Sign in" }).click();

  await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();

  await page.context().storageState({ path: SHOPPER_SESSION });
});
