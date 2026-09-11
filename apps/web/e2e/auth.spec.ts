import { expect, test } from "@playwright/test";

import { linkFromInbox } from "./support/mailpit";

/**
 * The whole account lifecycle, in a browser, through the real proxy and a real
 * inbox.
 *
 * This is the check curl could not make. Every earlier verification called the
 * API the way the forms do, and none of it ran the forms: the session cookie
 * arriving through the proxy, the CSRF token being read from `document.cookie`,
 * `router.refresh()` redrawing the header - all of that happens in the page.
 *
 * Each run registers `e2e-<timestamp>@example.test` and leaves it behind. The
 * browser cannot delete a user and must not be able to, so there is no
 * teardown that respects the boundary (root CLAUDE.md section 4).
 */
test("an account, from registering to resetting a forgotten password", async ({ page }) => {
  const email = `e2e-${Date.now()}@example.test`;
  const password = "correct-horse-battery-e2e";
  const changed = "a-different-password-e2e";

  await test.step("register, and land on the page that says mail is coming", async () => {
    await page.goto("/register");
    await page.getByLabel("Name").fill("Playwright");
    await page.getByLabel("Email").fill(email);
    await page.getByLabel("Password", { exact: true }).fill(password);
    await page.getByLabel("Confirm password").fill(password);
    await page.getByRole("button", { name: "Create account" }).click();

    await expect(page).toHaveURL(/\/verify-email\/sent$/);
    await expect(page.getByRole("heading", { name: "Check your inbox" })).toBeVisible();
    await expect(page.getByText(email)).toBeVisible();
  });

  await test.step("follow the link in the email", async () => {
    await page.goto(await linkFromInbox(email, "/verify-email"));

    await expect(page.getByText("Your email address is confirmed.")).toBeVisible();
  });

  await test.step("the header knows who is signed in, and signs them out", async () => {
    await page.goto("/");
    const [signedOut] = await Promise.all([
      page.waitForResponse((response) => response.url().endsWith("/api/v1/auth/logout")),
      page.getByRole("button", { name: "Sign out" }).click(),
    ]);

    // The write itself succeeded. What follows is whether the page noticed -
    // two different failures that look identical from the outside.
    expect(signedOut.status()).toBe(204);

    await expect(page.getByRole("link", { name: "Sign in" })).toBeVisible();
  });

  await test.step("a wrong password keeps the email address in the field", async () => {
    await page.goto("/login");
    await page.getByLabel("Email").fill(email);
    await page.getByLabel("Password").fill("not-the-password-at-all");
    await page.getByRole("button", { name: "Sign in" }).click();

    await expect(page.getByLabel("Email")).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByLabel("Email")).toHaveValue(email);
  });

  await test.step("the right one signs in and goes home", async () => {
    await page.getByLabel("Password").fill(password);
    await page.getByRole("button", { name: "Sign in" }).click();

    await expect(page).toHaveURL(/\/$/);
    await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();
    const [signedOut] = await Promise.all([
      page.waitForResponse((response) => response.url().endsWith("/api/v1/auth/logout")),
      page.getByRole("button", { name: "Sign out" }).click(),
    ]);

    // The write itself succeeded. What follows is whether the page noticed -
    // two different failures that look identical from the outside.
    expect(signedOut.status()).toBe(204);
    await expect(page.getByRole("link", { name: "Sign in" })).toBeVisible();
  });

  await test.step("a forgotten password is reset from the emailed link", async () => {
    await page.goto("/forgot-password");
    await page.getByLabel("Email").fill(email);
    await page.getByRole("button", { name: "Email me a link" }).click();
    await expect(page.getByText("is on its way")).toBeVisible();

    await page.goto(await linkFromInbox(email, "/reset-password"));
    await page.getByLabel("New password", { exact: true }).fill(changed);
    await page.getByLabel("Confirm new password").fill(changed);
    await page.getByRole("button", { name: "Change password" }).click();

    await expect(page.getByText("Your password has been changed.")).toBeVisible();
  });

  await test.step("and the new password is the one that works", async () => {
    await page.goto("/login");
    await page.getByLabel("Email").fill(email);
    await page.getByLabel("Password").fill(changed);
    await page.getByRole("button", { name: "Sign in" }).click();

    await expect(page.getByRole("button", { name: "Sign out" })).toBeVisible();
  });
});
