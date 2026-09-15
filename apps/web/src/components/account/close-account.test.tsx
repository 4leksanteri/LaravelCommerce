import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { loadFresh } from "@/lib/navigation";

import { CloseAccount } from "./close-account";

vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));
vi.mock("@/lib/navigation", () => ({ loadFresh: vi.fn() }));

const request = vi.mocked(apiFetch);
const goHome = vi.mocked(loadFresh);

/**
 * Closing an account (ADR 0058).
 *
 * What this component promises: that it asks before it does anything, that it
 * says what is kept while somebody is deciding rather than afterwards, that a
 * refusal is the API's own sentence, and that success ends in a full page load
 * because the session is gone.
 */
beforeEach(() => {
  request.mockReset();
  goHome.mockReset();
});

describe("CloseAccount", () => {
  it("says what is kept before asking anybody to decide", () => {
    render(<CloseAccount />);

    expect(screen.getByText(/Orders you have placed are kept/)).toBeVisible();
    expect(screen.getByText(/stays where it is without your name on it/)).toBeVisible();
  });

  /** The button that starts it is not the button that does it. */
  it("asks first, and sends nothing until it is confirmed", async () => {
    const user = userEvent.setup();
    render(<CloseAccount />);

    expect(screen.queryByLabelText("Current password")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Close my account" }));

    expect(screen.getByText(/This cannot be undone/)).toBeVisible();
    expect(request).not.toHaveBeenCalled();
  });

  it("goes back without sending anything", async () => {
    const user = userEvent.setup();
    render(<CloseAccount />);

    await user.click(screen.getByRole("button", { name: "Close my account" }));
    await user.click(screen.getByRole("button", { name: "Keep my account" }));

    expect(screen.getByRole("button", { name: "Close my account" })).toBeVisible();
    expect(request).not.toHaveBeenCalled();
  });

  it("sends the password and lands on a new document", async () => {
    request.mockResolvedValue(undefined);
    const user = userEvent.setup();
    render(<CloseAccount />);

    await user.click(screen.getByRole("button", { name: "Close my account" }));
    await user.type(screen.getByLabelText("Current password"), "correct-horse-battery");
    await user.click(screen.getByRole("button", { name: "Close my account for good" }));

    expect(request).toHaveBeenCalledWith(
      "/account",
      expect.objectContaining({
        method: "DELETE",
        body: JSON.stringify({ current_password: "correct-horse-battery" }),
      }),
    );

    // Not a client-side navigation: the session is destroyed server-side, so
    // every render the router is holding belongs to somebody signed in.
    expect(goHome).toHaveBeenCalledWith("/");
  });

  /**
   * The refusal is the API's, and it names which unfinished thing is in the
   * way. Re-deriving that here would mean fetching an order list to say
   * something the 409 already said.
   */
  it("shows what the API said when something is unfinished", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "You have orders that have not finished." }),
    );

    const user = userEvent.setup();
    render(<CloseAccount />);

    await user.click(screen.getByRole("button", { name: "Close my account" }));
    await user.type(screen.getByLabelText("Current password"), "correct-horse-battery");
    await user.click(screen.getByRole("button", { name: "Close my account for good" }));

    expect(await screen.findByText(/orders that have not finished/)).toBeVisible();
    expect(goHome).not.toHaveBeenCalled();
  });

  it("puts a wrong password beside its field", async () => {
    request.mockRejectedValue(
      new ApiError(422, {
        message: "The given data was invalid.",
        errors: { current_password: ["That is not your current password."] },
      }),
    );

    const user = userEvent.setup();
    render(<CloseAccount />);

    await user.click(screen.getByRole("button", { name: "Close my account" }));
    await user.type(screen.getByLabelText("Current password"), "wrong");
    await user.click(screen.getByRole("button", { name: "Close my account for good" }));

    expect(await screen.findByText("That is not your current password.")).toBeVisible();
    expect(goHome).not.toHaveBeenCalled();
  });
});
