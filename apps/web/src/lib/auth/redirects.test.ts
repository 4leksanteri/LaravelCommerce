// @vitest-environment node
import { describe, expect, it } from "vitest";

import { safeRedirect } from "./redirects";

/**
 * `?next=` is attacker-controlled. Each hostile case below is a real way to
 * send somebody through the genuine sign-in page and out to a convincing copy,
 * carrying the trust of having just authenticated.
 */
describe("safeRedirect", () => {
  it.each([
    ["/orders", "/orders"],
    ["/search?q=lens&page=2", "/search?q=lens&page=2"],
    ["/verify-email/sent", "/verify-email/sent"],
  ])("honours a path on this origin: %s", (target, expected) => {
    expect(safeRedirect(target)).toBe(expected);
  });

  it.each([null, undefined, ""])("sends somebody home when there is no target: %s", (target) => {
    expect(safeRedirect(target)).toBe("/");
  });

  it.each([
    ["another origin outright", "https://elsewhere.test/login"],
    ["protocol-relative, which reads as a path", "//elsewhere.test"],
    ["a backslash some browsers normalise to a slash", "/\\elsewhere.test"],
    ["a script URL", "javascript:alert(1)"],
    ["relative, so it resolves against wherever the person is", "orders"],
    // Browsers strip tabs and newlines out of a URL before parsing it, so
    // both of these become `//elsewhere.test` by the time anything navigates.
    ["a tab hidden between the slashes", "/\t/elsewhere.test"],
    ["a newline hidden between the slashes", "/\n/elsewhere.test"],
  ])("refuses %s", (_, target) => {
    expect(safeRedirect(target)).toBe("/");
  });
});
