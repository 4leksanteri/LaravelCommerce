import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

import { AppealControl } from "./appeal-control";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const props = {
  path: "/seller/appeal",
  subject: "the suspension",
  whileWaiting: "Your shop stays suspended until they do.",
  canAppeal: true,
  hasOpenAppeal: false,
};

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
  request.mockReset();
});

describe("AppealControl", () => {
  /** The API's answer, drawn rather than re-derived. */
  it("offers nothing when there is nothing to appeal", () => {
    const { container } = render(<AppealControl {...props} canAppeal={false} />);

    expect(container).toBeEmptyDOMElement();
  });

  /**
   * `can_appeal` is false both when there is nothing to appeal and when one is
   * already waiting. Without `has_open_appeal` those are the same empty page,
   * and somebody who appealed yesterday would find no sign of it.
   */
  it("says an appeal is waiting rather than falling silent", () => {
    render(<AppealControl {...props} canAppeal={false} hasOpenAppeal />);

    expect(screen.getByText(/You have appealed/)).toBeVisible();
    expect(screen.queryByRole("button", { name: /Appeal/ })).not.toBeInTheDocument();
  });

  it("sends the argument, and promises a second look rather than a reversal", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<AppealControl {...props} />);
    await user.click(screen.getByRole("button", { name: "Appeal the suspension" }));

    await user.type(
      screen.getByLabelText("Why was this decision wrong?"),
      "The disputes were all one order, and it was refunded in full.",
    );
    await user.click(screen.getByRole("button", { name: "Send the appeal" }));

    expect(request).toHaveBeenCalledWith(
      "/seller/appeal",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          reason: "The disputes were all one order, and it was refunded in full.",
        }),
      }),
    );

    // What was appealed is still stopped, and the confirmation has to say so.
    expect(await screen.findByText(/Your shop stays suspended until they do/)).toBeVisible();
  });

  /** Nothing is redrawn, so the form cannot come back as though nothing had happened. */
  it("does not redraw the page after appealing", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<AppealControl {...props} />);
    await user.click(screen.getByRole("button", { name: "Appeal the suspension" }));
    await user.type(screen.getByLabelText("Why was this decision wrong?"), "A sentence about it.");
    await user.click(screen.getByRole("button", { name: "Send the appeal" }));

    expect(await screen.findByText(/You have appealed/)).toBeVisible();
    expect(router.refresh).not.toHaveBeenCalled();
  });

  it("puts a refused reason beside the field", async () => {
    const message = "The reason must be at least 10 characters.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { reason: [message] } }));
    const user = userEvent.setup();

    render(<AppealControl {...props} />);
    await user.click(screen.getByRole("button", { name: "Appeal the suspension" }));
    await user.type(screen.getByLabelText("Why was this decision wrong?"), "too short");
    await user.click(screen.getByRole("button", { name: "Send the appeal" }));

    expect(await screen.findByText(message)).toBeVisible();
  });

  /** Already appealing is the ordinary case, and the API's sentence says which. */
  it("shows the API's reason when an appeal is already open", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "There is nothing to appeal, or an appeal is already open." }),
    );
    const user = userEvent.setup();

    render(<AppealControl {...props} />);
    await user.click(screen.getByRole("button", { name: "Appeal the suspension" }));
    await user.type(screen.getByLabelText("Why was this decision wrong?"), "A sentence about it.");
    await user.click(screen.getByRole("button", { name: "Send the appeal" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "There is nothing to appeal, or an appeal is already open.",
    );
  });
});
