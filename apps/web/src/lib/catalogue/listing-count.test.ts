// @vitest-environment node
import { describe, expect, it } from "vitest";

import { listingCount } from "./listing-count";

describe("listingCount", () => {
  it.each([
    [0, "0 listings"],
    [1, "1 listing"],
    [24, "24 listings"],
    [1204, "1,204 listings"],
  ])("words %i as %s", (total, expected) => {
    expect(listingCount(total)).toBe(expected);
  });
});
