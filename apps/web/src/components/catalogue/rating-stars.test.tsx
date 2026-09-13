import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { RatingStars } from "./rating-stars";

/**
 * The rating, as a card and a listing show it (ADR 0047).
 *
 * The number beside the stars is the truth - five shapes cannot say 4.3 - so
 * what is asserted here is the number, the wording around it, and that a
 * listing nobody has reviewed draws nothing at all.
 */
describe("RatingStars", () => {
  it("says nothing about a listing nobody has reviewed", () => {
    const { container } = render(<RatingStars rating={null} count={0} />);

    expect(container).toBeEmptyDOMElement();
  });

  /** A count without a rating should not happen, and still must not read as zero. */
  it("says nothing when there is a count but no rating", () => {
    const { container } = render(<RatingStars rating={null} count={3} />);

    expect(container).toBeEmptyDOMElement();
  });

  it("writes a fraction to one place", () => {
    render(<RatingStars rating={4.5} count={2} />);

    expect(screen.getByText("4.5")).toBeVisible();
  });

  /** Four, not 4.0: `toFixed` would write the second and it reads as precision. */
  it("writes a whole rating without a decimal", () => {
    render(<RatingStars rating={4} count={2} />);

    expect(screen.getByText("4")).toBeVisible();
  });

  it("counts one review in the singular", () => {
    render(<RatingStars rating={5} count={1} />);

    expect(screen.getByText("(1 review)")).toBeVisible();
  });

  it("counts the rest in the plural", () => {
    render(<RatingStars rating={5} count={12} />);

    expect(screen.getByText("(12 reviews)")).toBeVisible();
  });
});
