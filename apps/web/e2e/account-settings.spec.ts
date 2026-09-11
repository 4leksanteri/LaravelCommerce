import { expect, test } from "@playwright/test";

import { expectFitsAPhoneAndPassesAxe } from "./support/checks";
import { linkFromInbox } from "./support/mailpit";
import { apiCall, SETTINGS_TESTER, SHOPPER_SESSION, signIn } from "./support/session";

/**
 * The account's settings and its address book (ADR 0034).
 *
 * The name and the address book are the shopper's, and each test puts back
 * what it changed. The email address and the password belong to an account
 * used for nothing else, because changing a password signs out every other
 * session - including the shopper's saved one, which every other test uses.
 */
test.describe("the demo shopper", () => {
  test.use({ storageState: SHOPPER_SESSION });

  test("changes their name, and the sidebar follows", async ({ page }) => {
    await page.goto("/account/settings");

    const form = page.getByRole("form", { name: "Your name" });
    const field = form.getByLabel("Name");
    const original = await field.inputValue();

    try {
      await field.fill("Demo shopper, renamed");
      await form.getByRole("button", { name: "Save name" }).click();

      await expect(form.getByText("Saved.")).toBeVisible();
      await expect(
        page
          .getByRole("complementary", { name: "Your account and shop" })
          .getByText("Demo shopper, renamed"),
      ).toBeVisible();
    } finally {
      const restored = await apiCall(page, "PATCH", "/account", { name: original });
      expect(restored.ok(), "putting the name back").toBe(true);
    }
  });

  test("keeps an address book: adds an entry, changes it, and removes it", async ({ page }) => {
    const street = `Asetuskatu ${Date.now()}`;

    await page.goto("/account/addresses");

    // The shopper's book fills up with every checkout run's address, so it is
    // rarely empty - but when it is, the form is already open.
    const add = page.getByRole("button", { name: "Add an address" });
    if (await add.count()) {
      await add.click();
    }

    const form = page.getByRole("form", { name: "New address" });
    await form.getByLabel("Recipient").fill("Demo Shopper");
    await form.getByLabel("Address", { exact: true }).fill(street);
    await form.getByLabel("City").fill("Helsinki");
    await form.getByLabel("Country").fill("FI");
    await form.getByRole("button", { name: "Save address" }).click();

    const entry = page.getByRole("listitem").filter({ hasText: street });
    await expect(entry).toContainText("Helsinki");

    await entry.getByRole("button", { name: /^Edit/ }).click();
    const edit = page.getByRole("form", { name: "Edit address" });
    await edit.getByLabel("City").fill("Tampere");
    await edit.getByRole("button", { name: "Save address" }).click();
    await expect(entry).toContainText("Tampere");

    await entry.getByRole("button", { name: /^Remove/ }).click();
    await entry.getByRole("button", { name: "Yes, remove it" }).click();
    await expect(page.getByRole("listitem").filter({ hasText: street })).toHaveCount(0);
  });

  test("the settings and the address book fit a phone and pass axe", async ({ page }) => {
    await expectFitsAPhoneAndPassesAxe(page, "/account/settings");
    await expectFitsAPhoneAndPassesAxe(page, "/account/addresses");
  });
});

test("an account moves to a new address and changes its password", async ({ page }) => {
  await signIn(page, SETTINGS_TESTER);
  await page.goto("/account/settings");

  await test.step("a new address is sent a link, and the account waits for it", async () => {
    const moved = `demo-settings+${Date.now()}@example.test`;
    const form = page.getByRole("form", { name: "Email address" });

    await form.getByLabel("New email address").fill(moved);
    await form.getByLabel("Current password").fill(SETTINGS_TESTER.password);
    await form.getByRole("button", { name: "Change email address" }).click();

    await expect(form.getByText(`We sent a link to ${moved}`, { exact: false })).toBeVisible();
    expect(await linkFromInbox(moved, "/verify-email")).toContain("/verify-email?");
    await expect(page.getByText(`Now ${moved}, not confirmed yet.`)).toBeVisible();

    // And back again, so the next run finds the account at its own address.
    await form.getByLabel("New email address").fill(SETTINGS_TESTER.email);
    await form.getByLabel("Current password").fill(SETTINGS_TESTER.password);
    await form.getByRole("button", { name: "Change email address" }).click();
    await expect(page.getByText(`Now ${SETTINGS_TESTER.email}, not confirmed yet.`)).toBeVisible();
  });

  await test.step("a new password, and this session keeps going", async () => {
    const replacement = `a new and longer password ${Date.now()}`;
    const form = page.getByRole("form", { name: "Password" });

    await form.getByLabel("Current password").fill(SETTINGS_TESTER.password);
    await form.getByLabel("New password", { exact: true }).fill(replacement);
    await form.getByLabel("Confirm new password").fill(replacement);
    await form.getByRole("button", { name: "Change password" }).click();

    await expect(form.getByText(/^Changed\./)).toBeVisible();

    // Still signed in here: the session that made the change was kept.
    await page.goto("/account");
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Your account");

    // And the new password is the one that works. `make seed-demo` puts the
    // old one back before the next run.
    // Signing out is a full page load (ADR 0025). Going to the sign-in page
    // before it has finished aborts one navigation with the other, which is
    // how the first run of this test failed.
    await page.getByRole("button", { name: "Sign out" }).click();
    await expect(page.getByRole("link", { name: "Sign in" })).toBeVisible();
    await signIn(page, { email: SETTINGS_TESTER.email, password: replacement });
  });
});
