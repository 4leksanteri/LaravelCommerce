// @vitest-environment node
import { describe, expect, it } from "vitest";

import { categoryHref } from "./category-href";

describe("categoryHref", () => {
  it("is the category's own address on its first page", () => {
    expect(categoryHref("audio")).toBe("/categories/audio");
    expect(categoryHref("audio", 1)).toBe("/categories/audio");
  });

  it("adds the page after the first", () => {
    expect(categoryHref("audio", 2)).toBe("/categories/audio?page=2");
  });

  it("encodes the slug rather than trusting it", () => {
    expect(categoryHref("a/b c")).toBe("/categories/a%2Fb%20c");
  });
});
