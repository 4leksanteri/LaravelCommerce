import { render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";

import type { Review } from "@/lib/api/types";

import { ReviewList } from "./review-list";

/*
 * A review the reader may report draws `ReportControl`, which is a client
 * component and reaches for the router - and there is no app router mounted
 * under Vitest. Mocked here, as every component test that renders one does
 * (apps/web/CLAUDE.md section 12a).
 */
vi.mock("next/navigation", () => ({ useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }) }));

/**
 * A listing's reviews, as anybody reading it sees them (ADR 0047).
 *
 * The empty state is here rather than end to end: it needs a listing nothing
 * has ever been bought from, and asserting against demo data that happens to
 * stay untouched is a test that breaks when the catalogue changes for unrelated
 * reasons.
 */
function review(overrides: Partial<Review> = {}): Review {
  return {
    id: 1,
    rating: 4,
    body: "Keeps time within ten seconds a day.",
    author: "Aino V.",
    written_at: "2026-03-01T10:00:00+00:00",
    was_edited: false,

    // The API's answer, and false for the signed-out reader these tests are
    // (ADR 0054). A review shows no report control then, deliberately.
    can_report: false,
    ...overrides,
  };
}

/** The listing they belong to, which a report has to name. */
const LISTING = { shopSlug: "second-hand-time", productSlug: "seiko-5-automatic" };

describe("ReviewList", () => {
  it("says nobody has reviewed it, and says who reviews can come from", () => {
    render(<ReviewList reviews={[]} {...LISTING} />);

    expect(screen.queryByRole("list")).not.toBeInTheDocument();
    expect(screen.getByText(/Nobody has reviewed this yet/)).toBeVisible();
    expect(screen.getByText(/confirmed it arrived/)).toBeVisible();
  });

  it("shows what somebody said, and who said it", () => {
    render(<ReviewList reviews={[review()]} {...LISTING} />);

    expect(screen.getByText("Keeps time within ten seconds a day.")).toBeVisible();
    expect(screen.getByText(/Aino V\./)).toBeVisible();
    expect(screen.getByText(/1 Mar 2026/)).toBeVisible();
  });

  /** A rating on its own is a review, and renders without an empty paragraph. */
  it("shows a review that has no words", () => {
    render(<ReviewList reviews={[review({ body: null })]} {...LISTING} />);

    expect(screen.getByRole("listitem")).toBeVisible();
    expect(screen.getByText(/Aino V\./)).toBeVisible();
  });

  /**
   * A reader is entitled to know whether they are looking at a first impression
   * or a corrected one.
   */
  it("marks a review that has been rewritten", () => {
    render(<ReviewList reviews={[review({ was_edited: true })]} {...LISTING} />);

    expect(screen.getByText(/\(edited\)/)).toBeVisible();
  });

  it("does not mark one that has stood since it was written", () => {
    render(<ReviewList reviews={[review()]} {...LISTING} />);

    expect(screen.queryByText(/\(edited\)/)).not.toBeInTheDocument();
  });

  /**
   * The API's answer, drawn rather than re-derived (ADR 0054). A guest and the
   * review's own author both get `can_report: false`, and this component does
   * not know or care which of the two it is looking at.
   */
  it("offers no way to report a review the reader may not report", () => {
    render(<ReviewList reviews={[review()]} {...LISTING} />);

    expect(screen.queryByRole("button", { name: /Report/ })).not.toBeInTheDocument();
  });

  it("offers to report one the reader may", () => {
    render(<ReviewList reviews={[review({ can_report: true })]} {...LISTING} />);

    expect(screen.getByRole("button", { name: "Report this review" })).toBeVisible();
  });
});
