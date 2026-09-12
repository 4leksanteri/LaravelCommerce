import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

import { PayoutIdentityDocument } from "./payout-identity-document";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

function aFile(name: string) {
  return new File(["not really a passport"], name, { type: "image/png" });
}

function sent(): FormData {
  const [, init] = request.mock.calls[0] as [string, RequestInit];

  return init.body as FormData;
}

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("PayoutIdentityDocument", () => {
  /** The browser writes the content type, with its boundary. */
  it("sends the front as multipart, without naming the content type", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<PayoutIdentityDocument />);
    await user.upload(screen.getByLabelText("The front"), aFile("front.png"));
    await user.click(screen.getByRole("button", { name: "Send the document" }));

    const [path, init] = request.mock.calls[0] as [string, RequestInit];

    expect(path).toBe("/seller/payout-account/identity-document");
    expect(init.method).toBe("POST");
    expect(init.headers).toBeUndefined();
    expect(sent().get("front")).toBeInstanceOf(File);
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  /**
   * A passport has no back, and an untouched file input still serialises - the
   * API would read the empty part as a file that is not one.
   */
  it("leaves out a back that was never chosen", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<PayoutIdentityDocument />);
    await user.upload(screen.getByLabelText("The front"), aFile("passport.png"));
    await user.click(screen.getByRole("button", { name: "Send the document" }));

    expect(sent().has("back")).toBe(false);
  });

  it("sends both sides when both were chosen", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<PayoutIdentityDocument />);
    await user.upload(screen.getByLabelText("The front"), aFile("front.png"));
    await user.upload(screen.getByLabelText("The back"), aFile("back.png"));
    await user.click(screen.getByRole("button", { name: "Send the document" }));

    expect(sent().get("front")).toBeInstanceOf(File);
    expect(sent().get("back")).toBeInstanceOf(File);
  });

  it("waits for a file before offering to send anything", () => {
    render(<PayoutIdentityDocument />);

    expect(screen.getByRole("button", { name: "Send the document" })).toBeDisabled();
  });

  it("puts a refused file beside the input", async () => {
    const message = "The front must be a file of type: jpg, jpeg, png, pdf.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { front: [message] } }));
    const user = userEvent.setup();

    render(<PayoutIdentityDocument />);
    await user.upload(screen.getByLabelText("The front"), aFile("front.png"));
    await user.click(screen.getByRole("button", { name: "Send the document" }));

    expect(await screen.findByText(message)).toBeVisible();
  });
});
