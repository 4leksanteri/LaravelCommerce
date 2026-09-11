import { render, screen } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { SearchField } from "./search-field";

const location = { pathname: "/", query: "" };

vi.mock("next/navigation", () => ({
  usePathname: () => location.pathname,
  useSearchParams: () => new URLSearchParams(location.query),
}));

beforeEach(() => {
  location.pathname = "/";
  location.query = "";
});

describe("SearchField", () => {
  it("shows the search being looked at", () => {
    location.pathname = "/search";
    location.query = "q=olympus&page=2";

    render(<SearchField />);

    expect(screen.getByRole("searchbox", { name: "Search listings" })).toHaveValue("olympus");
  });

  it("is empty everywhere else, whatever the query string says", () => {
    location.pathname = "/";
    location.query = "q=olympus";

    render(<SearchField />);

    expect(screen.getByRole("searchbox", { name: "Search listings" })).toHaveValue("");
  });

  it("is named q, which is the whole contract with the form around it", () => {
    render(<SearchField />);

    expect(screen.getByRole("searchbox", { name: "Search listings" })).toHaveAttribute("name", "q");
  });

  /**
   * A search made from the results page changes the URL without remounting
   * the header. `defaultValue` only applies on mount, so without a new key the
   * box would keep showing the previous term.
   */
  it("follows the query when it changes under it", () => {
    location.pathname = "/search";
    location.query = "q=olympus";

    const { rerender } = render(<SearchField />);

    location.query = "q=canon";
    rerender(<SearchField />);

    expect(screen.getByRole("searchbox", { name: "Search listings" })).toHaveValue("canon");
  });
});
