import AxeBuilder from "@axe-core/playwright";
import { expect, type Page } from "@playwright/test";

/**
 * The two checks every page gets (ADR 0025): nothing scrolls sideways at phone
 * width, and axe finds nothing.
 *
 * For pages behind a session. pages.spec runs signed out, so the account's
 * pages and the shop's cannot be in its list; each spec that owns one calls
 * this instead of repeating the same dozen lines.
 */
export async function expectFitsAPhoneAndPassesAxe(page: Page, path: string): Promise<void> {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto(path);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();

  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - window.innerWidth,
  );
  expect(overflow, `${path}: pixels of horizontal overflow`).toBeLessThanOrEqual(0);

  const { violations } = await new AxeBuilder({ page }).analyze();
  expect(
    violations.map((violation) => `${violation.id}: ${violation.help} (${violation.nodes.length})`),
    path,
  ).toEqual([]);
}
