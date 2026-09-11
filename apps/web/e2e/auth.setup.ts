import { expect, test as setup } from "@playwright/test";

import { SELLER, SELLER_SESSION, SHOPPER, SHOPPER_SESSION } from "./support/session";

/**
 * Signs in once as each demo account the suite uses, through the real form,
 * and saves each session for every test that needs it.
 *
 * The only sign-ins the suite spends on the shared accounts. See
 * support/session.ts for why it is one each and not one per test.
 */
setup("sign in as the demo shopper, once for the whole run", async ({ page }) => {
  await page.goto("/login");
  await page.getByLabel("Email").fill(SHOPPER.email);
  await page.getByLabel("Password").fill(SHOPPER.password);
  await page.getByRole("button", { name: "Sign in" }).click();

  await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();

  await page.context().storageState({ path: SHOPPER_SESSION });
});

setup("sign in as a demo shop owner, once for the whole run", async ({ page }) => {
  await page.goto("/login");
  await page.getByLabel("Email").fill(SELLER.email);
  await page.getByLabel("Password").fill(SELLER.password);
  await page.getByRole("button", { name: "Sign in" }).click();

  await expect(page.getByRole("link", { name: "Your shop" })).toBeVisible();

  await page.context().storageState({ path: SELLER_SESSION });
});
