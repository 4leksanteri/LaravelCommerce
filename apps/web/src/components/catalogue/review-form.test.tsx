import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import type { Review } from "@/lib/api/types";

import { ReviewForm } from "./review-form";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const existing: Review = {
  id: 7,
  rating: 2,
  body: "The strap broke.",
  author: "Aino V.",
  written_at: "2026-03-01T10:00:00+00:00",
  was_edited: false,

  // Your own, so there is nothing to report (ADR 0054). The way to take back
  // what you wrote is this form.
  can_report: false,
};

/**
 * Writing a review and changing one (ADR 0047).
 *
 * What this component promises: the right verb, the rating somebody chose, and
 * words that can be removed. How a refusal is classified is `useApiSubmit`'s
 * own test rather than repeated here.
 */
beforeEach(() => {
  vi.clearAllMocks();
  request.mockResolvedValue({ data: existing });
});

describe("ReviewForm", () => {
  it("leaves a new review with the rating and the words", async () => {
    render(<ReviewForm shopSlug="second-hand-time" productSlug="seiko-5" existing={null} />);

    await userEvent.click(screen.getByRole("radio", { name: "4" }));
    await userEvent.type(screen.getByLabelText(/What you thought/), "Keeps good time.");
    await userEvent.click(screen.getByRole("button", { name: "Leave review" }));

    expect(request).toHaveBeenCalledWith(
      "/shops/second-hand-time/products/seiko-5/reviews",
      expect.objectContaining({ method: "POST" }),
    );

    const sent = JSON.parse(String(request.mock.calls[0]?.[1]?.body)) as unknown;

    expect(sent).toEqual({ rating: 4, body: "Keeps good time." });
  });

  /** A revision replaces the verdict, so it is a PATCH and it sends both fields. */
  it("changes an existing review rather than leaving a second", async () => {
    render(<ReviewForm shopSlug="second-hand-time" productSlug="seiko-5" existing={existing} />);

    await userEvent.click(screen.getByRole("button", { name: "Save changes" }));

    expect(request).toHaveBeenCalledWith(
      "/shops/second-hand-time/products/seiko-5/reviews",
      expect.objectContaining({ method: "PATCH" }),
    );
  });

  it("starts from what they said last time", () => {
    render(<ReviewForm shopSlug="second-hand-time" productSlug="seiko-5" existing={existing} />);

    expect(screen.getByRole("radio", { name: "2" })).toBeChecked();
    expect(screen.getByLabelText(/What you thought/)).toHaveValue("The strap broke.");
  });

  /**
   * Clearing the box means "I have removed what I said", not "leave it alone" -
   * which is why the API takes a whole body on a revision.
   */
  it("sends no words when the box is emptied", async () => {
    render(<ReviewForm shopSlug="second-hand-time" productSlug="seiko-5" existing={existing} />);

    await userEvent.clear(screen.getByLabelText(/What you thought/));
    await userEvent.click(screen.getByRole("button", { name: "Save changes" }));

    const sent = JSON.parse(String(request.mock.calls[0]?.[1]?.body)) as { body: string | null };

    expect(sent.body).toBeNull();
  });

  it("redraws the page once it is saved, so the listing shows it", async () => {
    render(<ReviewForm shopSlug="second-hand-time" productSlug="seiko-5" existing={null} />);

    await userEvent.click(screen.getByRole("button", { name: "Leave review" }));

    expect(screen.getByText("Saved.")).toBeVisible();
    expect(router.refresh).toHaveBeenCalled();
  });
});
