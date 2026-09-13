import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Dispute } from "@/lib/api/types";

import { DisputePanel } from "./dispute-panel";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const open: Dispute = {
  id: 1,
  reason: "It never arrived, and tracking has not moved in two weeks.",
  is_open: true,
  resolution: null,
  resolution_note: null,
  opened_at: "2026-03-05T10:00:00+00:00",
  resolved_at: null,
};

const refunded: Dispute = {
  ...open,
  is_open: false,
  resolution: "refunded",
  resolution_note: "The carrier never scanned it.",
  resolved_at: "2026-03-08T10:00:00+00:00",
};

function panel(props: Partial<React.ComponentProps<typeof DisputePanel>> = {}) {
  return (
    <DisputePanel
      dispute={null}
      canDispute={false}
      endpoint="/orders/K7M2QXV9RT"
      page="/account/orders/K7M2QXV9RT"
      viewer="buyer"
      {...props}
    />
  );
}

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
  request.mockReset();
  request.mockResolvedValue({ data: open });
});

describe("DisputePanel", () => {
  /**
   * The window is the API's answer, never re-derived: the browser cannot see
   * whether the money is still held (ADR 0051).
   */
  it("draws nothing when there is no dispute and none can be raised", () => {
    const { container } = render(panel());

    expect(container).toBeEmptyDOMElement();
  });

  it("offers to raise one when the API says it can be", () => {
    render(panel({ canDispute: true }));

    expect(screen.getByRole("button", { name: "Something went wrong" })).toBeVisible();
  });

  /** Raising one holds the shop's money and cannot be taken back, so it asks. */
  it("asks what went wrong before sending anything", async () => {
    const user = userEvent.setup();

    render(panel({ canDispute: true }));
    await user.click(screen.getByRole("button", { name: "Something went wrong" }));

    expect(request).not.toHaveBeenCalled();
    expect(screen.getByLabelText("What went wrong?")).toHaveFocus();
    expect(screen.getByText(/cannot take a dispute back/)).toBeVisible();
  });

  it("sends the reason and redraws the page", async () => {
    const user = userEvent.setup();

    render(panel({ canDispute: true }));
    await user.click(screen.getByRole("button", { name: "Something went wrong" }));
    await user.type(screen.getByLabelText("What went wrong?"), "It never arrived.");
    await user.click(screen.getByRole("button", { name: "Raise a dispute" }));

    expect(request).toHaveBeenCalledWith(
      "/orders/K7M2QXV9RT/dispute",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ reason: "It never arrived." }),
      }),
    );
    expect(router.refresh).toHaveBeenCalled();
  });

  it("puts the API's refusal beside the field", async () => {
    const message = "The reason field is required.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { reason: [message] } }));
    const user = userEvent.setup();

    render(panel({ canDispute: true }));
    await user.click(screen.getByRole("button", { name: "Something went wrong" }));
    await user.click(screen.getByRole("button", { name: "Raise a dispute" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.getByLabelText("What went wrong?")).toHaveAttribute("aria-invalid", "true");
  });

  it("sends somebody whose session ended to sign in, and back to the order", async () => {
    request.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));
    const user = userEvent.setup();

    render(panel({ canDispute: true }));
    await user.click(screen.getByRole("button", { name: "Something went wrong" }));
    await user.type(screen.getByLabelText("What went wrong?"), "It never arrived.");
    await user.click(screen.getByRole("button", { name: "Raise a dispute" }));

    await waitFor(() =>
      expect(router.push).toHaveBeenCalledWith("/login?next=%2Faccount%2Forders%2FK7M2QXV9RT"),
    );
  });

  // --- Once there is one ----------------------------------------------------

  it("shows an open dispute, and says the money is held", () => {
    render(panel({ dispute: open, canDispute: false }));

    expect(screen.getByText(/we are looking at it/i)).toBeVisible();
    expect(screen.getByText(open.reason)).toBeVisible();
    expect(screen.getByText(/payment is held until we have decided/)).toBeVisible();

    // Nothing to raise once one exists.
    expect(screen.queryByRole("button", { name: "Something went wrong" })).not.toBeInTheDocument();
  });

  /**
   * The same decision, told from each side. One of them is worse off than they
   * hoped, and both are entitled to the reasoning.
   */
  it("tells the buyer the decision went their way", () => {
    render(panel({ dispute: refunded, viewer: "buyer" }));

    expect(screen.getByText(/Decided in your favour/)).toBeVisible();
    expect(screen.getByText("The carrier never scanned it.")).toBeVisible();
  });

  it("tells the shop the same decision from their side", () => {
    render(panel({ dispute: refunded, viewer: "shop" }));

    expect(screen.getByText(/Decided in the buyer's favour/)).toBeVisible();
    expect(screen.getByText("The carrier never scanned it.")).toBeVisible();
  });

  it("names what the buyer said as the buyer's own words", () => {
    render(panel({ dispute: open, viewer: "buyer" }));

    expect(screen.getByText(/What you said/)).toBeVisible();
  });

  it("names what the buyer said as theirs, to the shop", () => {
    render(panel({ dispute: open, viewer: "shop" }));

    expect(screen.getByText(/What the buyer said/)).toBeVisible();
  });
});
