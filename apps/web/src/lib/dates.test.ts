import { describe, expect, it } from "vitest";

import { formatDate } from "./dates";

describe("formatDate", () => {
  it("reads the way a person writes a date", () => {
    expect(formatDate("2026-03-04T12:00:00+00:00")).toBe("4 Mar 2026");
  });

  /**
   * Late evening two hours west of Greenwich is early the next morning in UTC,
   * and the date shown is UTC's: the same on the server, in every browser and
   * in this test, whatever zone each of them is in.
   */
  it("is the date in UTC, wherever the moment was written from", () => {
    expect(formatDate("2026-03-04T23:30:00-02:00")).toBe("5 Mar 2026");
  });
});
