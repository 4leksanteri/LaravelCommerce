import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

import { ReportControl } from "./report-control";

vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push, refresh: vi.fn() }) }));

const PATH = "/shops/second-hand-time/products/seiko-5-automatic/reports";

/**
 * Reporting a listing or a review (ADR 0054).
 *
 * What this component promises, and what is worth holding it to: that a guest
 * is offered the way to sign in rather than a button that would 401, that the
 * reason reaches the API as the enum's own value, that `other` asks for words,
 * and above all that the confirmation never claims anything came down - a
 * report changes nothing until a person decides.
 */
describe("ReportControl", () => {
  beforeEach(() => {
    vi.mocked(apiFetch).mockReset();
    push.mockReset();
  });

  it("offers a guest the way to sign in rather than a button that would fail", () => {
    render(<ReportControl path={PATH} subject="this listing" signInHref="/login?next=%2Fhere" />);

    expect(screen.getByRole("link", { name: "Sign in" })).toHaveAttribute(
      "href",
      "/login?next=%2Fhere",
    );
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("sends the reason the API named, and the words beside it", async () => {
    vi.mocked(apiFetch).mockResolvedValue(undefined);

    const user = userEvent.setup();
    render(<ReportControl path={PATH} subject="this listing" />);

    await user.click(screen.getByRole("button", { name: "Report this listing" }));
    await user.selectOptions(screen.getByLabelText("What is wrong with it?"), "counterfeit");
    await user.type(screen.getByLabelText(/Anything to add/), "The serial is from another model.");
    await user.click(screen.getByRole("button", { name: "Report it" }));

    await waitFor(() => expect(apiFetch).toHaveBeenCalledOnce());

    expect(apiFetch).toHaveBeenCalledWith(
      PATH,
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          reason: "counterfeit",
          note: "The serial is from another model.",
        }),
      }),
    );
  });

  /** A note nobody typed is null rather than an empty string. */
  it("sends no words when none were written", async () => {
    vi.mocked(apiFetch).mockResolvedValue(undefined);

    const user = userEvent.setup();
    render(<ReportControl path={PATH} subject="this listing" />);

    await user.click(screen.getByRole("button", { name: "Report this listing" }));
    await user.click(screen.getByRole("button", { name: "Report it" }));

    await waitFor(() => expect(apiFetch).toHaveBeenCalledOnce());

    expect(apiFetch).toHaveBeenCalledWith(
      PATH,
      expect.objectContaining({ body: JSON.stringify({ reason: "counterfeit", note: null }) }),
    );
  });

  /**
   * **The confirmation must not claim anything happened to the listing.**
   * Nothing does until a person decides, and a marketplace that told reporters
   * otherwise would be teaching them that reporting is a delete button.
   */
  it("says it is being looked at, and that it is still on sale", async () => {
    vi.mocked(apiFetch).mockResolvedValue(undefined);

    const user = userEvent.setup();
    render(<ReportControl path={PATH} subject="this listing" />);

    await user.click(screen.getByRole("button", { name: "Report this listing" }));
    await user.click(screen.getByRole("button", { name: "Report it" }));

    expect(await screen.findByText(/it stays on sale until they do/)).toBeVisible();
    expect(screen.queryByRole("button", { name: "Report this listing" })).not.toBeInTheDocument();
  });

  /** Already reported, and the API's own sentence says so better than a generic one. */
  it("shows what the API said when this was already reported", async () => {
    const said = "You have already reported this, and we are still looking at it.";

    vi.mocked(apiFetch).mockRejectedValue(new ApiError(409, { message: said }, said));

    const user = userEvent.setup();
    render(<ReportControl path={PATH} subject="this listing" />);

    await user.click(screen.getByRole("button", { name: "Report this listing" }));
    await user.click(screen.getByRole("button", { name: "Report it" }));

    expect(await screen.findByText(/already reported this/)).toBeVisible();
  });

  it("sends a lapsed session to sign in", async () => {
    vi.mocked(apiFetch).mockRejectedValue(
      new ApiError(401, { message: "Unauthenticated." }, "Unauthenticated."),
    );

    const user = userEvent.setup();
    render(<ReportControl path={PATH} subject="this listing" />);

    await user.click(screen.getByRole("button", { name: "Report this listing" }));
    await user.click(screen.getByRole("button", { name: "Report it" }));

    await waitFor(() => expect(push).toHaveBeenCalledWith(expect.stringContaining("/login?next=")));
  });
});
