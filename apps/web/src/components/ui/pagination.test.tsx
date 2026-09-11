import { render, screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Pagination } from "./pagination";

const hrefFor = (page: number) => `/search?q=lens&page=${page}`;

// The ellipsis the pager draws between skipped pages. An escape rather than the
// character, which root CLAUDE.md section 15 keeps out of source.
const GAP = "\u2026";

/**
 * What the pager shows, gaps included. The gaps are `aria-hidden` on purpose -
 * they are drawn for the eye, and a screen reader should hear the page numbers
 * rather than "ellipsis" twice - so the query has to ask for hidden items too.
 */
function slots(): string[] {
  const nav = screen.getByRole("navigation", { name: "Pagination" });

  return within(nav)
    .getAllByRole("listitem", { hidden: true })
    .map((item) => item.textContent ?? "")
    .filter((text) => text !== "Previous" && text !== "Next");
}

describe("Pagination", () => {
  it("draws nothing for a set that fits on one page", () => {
    const { container } = render(<Pagination currentPage={1} lastPage={1} hrefFor={hrefFor} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("marks the current page, and links every other one", () => {
    render(<Pagination currentPage={2} lastPage={3} hrefFor={hrefFor} />);

    expect(screen.getByRole("link", { name: "2" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("link", { name: "3" })).toHaveAttribute(
      "href",
      "/search?q=lens&page=3",
    );
    expect(screen.getByRole("link", { name: "1" })).not.toHaveAttribute("aria-current");
  });

  it("has no previous page on the first, and no next page on the last", () => {
    const { rerender } = render(<Pagination currentPage={1} lastPage={3} hrefFor={hrefFor} />);

    expect(screen.queryByRole("link", { name: "Previous" })).toBeNull();
    expect(screen.getByText("Previous")).toHaveAttribute("aria-disabled", "true");
    expect(screen.getByRole("link", { name: "Next" })).toHaveAttribute("rel", "next");

    rerender(<Pagination currentPage={3} lastPage={3} hrefFor={hrefFor} />);

    expect(screen.queryByRole("link", { name: "Next" })).toBeNull();
    expect(screen.getByRole("link", { name: "Previous" })).toHaveAttribute("rel", "prev");
  });

  it("shows the ends and the neighbours of a long set, with gaps between", () => {
    render(<Pagination currentPage={5} lastPage={10} hrefFor={hrefFor} />);

    expect(slots()).toEqual(["1", GAP, "4", "5", "6", GAP, "10"]);
  });

  it("does not draw a gap where no page is skipped", () => {
    render(<Pagination currentPage={2} lastPage={4} hrefFor={hrefFor} />);

    expect(slots()).toEqual(["1", "2", "3", "4"]);
  });

  it("keeps the gaps out of what a screen reader announces", () => {
    render(<Pagination currentPage={5} lastPage={10} hrefFor={hrefFor} />);

    const announced = within(screen.getByRole("navigation", { name: "Pagination" }))
      .getAllByRole("listitem")
      .map((item) => item.textContent);

    expect(announced).not.toContain(GAP);
  });

  /**
   * `?page=40` of a three-page set is an empty page, not an error (ADR 0022).
   * The way back goes to the last page that exists, not to page 39.
   */
  it("offers a way back from past the end, to the last real page", () => {
    render(<Pagination currentPage={40} lastPage={3} hrefFor={hrefFor} />);

    expect(screen.getByRole("link", { name: "Previous" })).toHaveAttribute(
      "href",
      "/search?q=lens&page=3",
    );
    expect(screen.queryByRole("link", { name: "Next" })).toBeNull();
  });
});
