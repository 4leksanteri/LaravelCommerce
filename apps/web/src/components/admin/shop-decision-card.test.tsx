import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import type { ShopDecision } from "@/lib/api/types";

import { ShopDecisionCard } from "./shop-decision-card";

const suspension: ShopDecision = {
  id: 3,
  kind: "shop_suspended",
  counts_against_the_shop: true,
  reason: "Three disputes decided against it this month.",
  decided_at: "2026-03-02T10:00:00+00:00",
  subject: {
    kind: "shop",
    title: "Retuned Audio",
    href: "/shops/retuned-audio",
  },
};

describe("ShopDecisionCard", () => {
  it("names the decision in words rather than in the API's case", () => {
    render(
      <ul>
        <ShopDecisionCard decision={suspension} />
      </ul>,
    );

    expect(screen.getByRole("heading")).toHaveTextContent("Shop suspended");
    expect(screen.queryByText("shop_suspended")).not.toBeInTheDocument();
  });

  /** The words given at the time, which the shop's own columns no longer hold. */
  it("quotes the reason that was given", () => {
    render(
      <ul>
        <ShopDecisionCard decision={suspension} />
      </ul>,
    );

    expect(screen.getByText(/Three disputes decided against it this month/)).toBeVisible();
  });

  it("links a subject that has somewhere to go", () => {
    render(
      <ul>
        <ShopDecisionCard decision={suspension} />
      </ul>,
    );

    expect(screen.getByRole("link", { name: "Retuned Audio" })).toHaveAttribute(
      "href",
      "/shops/retuned-audio",
    );
  });

  /** A dispute has no page staff can follow, so its title is not a link. */
  it("leaves a subject with nowhere to go as plain words", () => {
    render(
      <ul>
        <ShopDecisionCard
          decision={{
            ...suspension,
            kind: "dispute_refunded",
            subject: { kind: "dispute", title: "Order AB12CD34", href: null },
          }}
        />
      </ul>,
    );

    expect(screen.getByText(/Order AB12CD34/)).toBeVisible();
    expect(screen.queryByRole("link")).not.toBeInTheDocument();
  });

  /**
   * A morph carries no foreign key, so a seller may delete the listing they
   * were punished over. The record stands and says so - a shop deleting what it
   * was punished for is itself worth seeing.
   */
  it("says so when what the decision was about has been deleted", () => {
    render(
      <ul>
        <ShopDecisionCard decision={{ ...suspension, kind: "listing_removed", subject: null }} />
      </ul>,
    );

    expect(screen.getByText(/has since been deleted/)).toBeVisible();
  });

  /** Lifting a suspension is not asked for a reason, so there is nothing to quote. */
  it("draws no quotation when no reason was given", () => {
    const { container } = render(
      <ul>
        <ShopDecisionCard decision={{ ...suspension, kind: "shop_reinstated", reason: null }} />
      </ul>,
    );

    expect(container.querySelector("blockquote")).toBeNull();
  });
});
