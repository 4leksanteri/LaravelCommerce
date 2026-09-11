import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

/**
 * Two properties every page must have, checked on every page that exists.
 *
 * Phone width was the one thing ADR 0024 admitted it had not seen: the layout
 * was reasoned about rather than looked at. This looks, and fails if anything
 * makes the page scroll sideways. A marketplace is browsed on a phone more
 * often than not (apps/web/CLAUDE.md section 12).
 *
 * axe finds the accessibility failures a machine can find - contrast, missing
 * names, broken ARIA references. It cannot find the rest, and a clean report is
 * a floor rather than a verdict.
 */
const PAGES = [
  "/",
  "/search",
  "/search?q=serviced",
  "/search?q=a",
  "/categories/audio",
  "/categories/headphones",
  "/categories/bicycles",
  "/login",
  "/register",
  "/forgot-password",
  "/nothing-lives-here",
];

test.describe("at phone width", () => {
  test.use({ viewport: { width: 375, height: 812 } });

  for (const path of PAGES) {
    test(`${path} does not scroll sideways`, async ({ page }) => {
      await page.goto(path);

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
      );

      expect(overflow, "pixels of horizontal overflow").toBeLessThanOrEqual(0);
    });
  }
});

for (const path of PAGES) {
  test(`${path} has no accessibility violations axe can find`, async ({ page }) => {
    await page.goto(path);

    const { violations } = await new AxeBuilder({ page }).analyze();

    expect(
      violations.map(
        (violation) => `${violation.id}: ${violation.help} (${violation.nodes.length})`,
      ),
    ).toEqual([]);
  });
}
