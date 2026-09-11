// @vitest-environment node
import { describe, expect, it } from "vitest";

import { searchHref } from "./search-href";

describe("searchHref", () => {
  it("is the bare page when there is nothing to search for", () => {
    expect(searchHref({})).toBe("/search");
    expect(searchHref({ q: "   ", category: null, page: 1 })).toBe("/search");
  });

  it("carries the term, trimmed", () => {
    expect(searchHref({ q: "  olympus  " })).toBe("/search?q=olympus");
  });

  it("writes the parameters in one order, so one search has one address", () => {
    expect(searchHref({ page: 3, category: "audio", q: "boxed" })).toBe(
      "/search?q=boxed&category=audio&page=3",
    );
  });

  it("never writes page 1, so page 1 and no page are the same address", () => {
    expect(searchHref({ q: "lens", page: 1 })).toBe("/search?q=lens");
    expect(searchHref({ q: "lens", page: 2 })).toBe("/search?q=lens&page=2");
  });

  it("encodes what people actually type", () => {
    expect(searchHref({ q: "om-1 & lens" })).toBe("/search?q=om-1+%26+lens");
  });
});
