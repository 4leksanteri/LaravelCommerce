import { render, screen } from "@testing-library/react";
import { StrictMode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

import { VerifyEmail } from "./verify-email";

vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const verify = vi.mocked(apiFetch);

beforeEach(() => {
  vi.spyOn(console, "error").mockImplementation(() => {});
});

const link = { id: "38", hash: "dc29/34", expires: "1789120000", signature: "a b+c" };

describe("VerifyEmail", () => {
  /**
   * `expires` before `signature`, and each part encoded. Today the order is
   * safe either way - Laravel drops `signature` and rejoins what is left, and
   * only `expires` is left - but a third parameter would make it load-bearing
   * at once, so both ends fix it (ADR 0023).
   */
  it("rebuilds the signed path exactly, expires first", async () => {
    verify.mockResolvedValue({ verified: true, already_verified: false });

    render(<VerifyEmail {...link} />);
    await screen.findByText("Your email address is confirmed.");

    expect(verify).toHaveBeenCalledWith(
      "/auth/email/verify/38/dc29%2F34?expires=1789120000&signature=a%20b%2Bc",
    );
  });

  it("asks once, even when React mounts it twice on purpose", async () => {
    verify.mockResolvedValue({ verified: true, already_verified: false });

    render(
      <StrictMode>
        <VerifyEmail {...link} />
      </StrictMode>,
    );
    await screen.findByText("Your email address is confirmed.");

    expect(verify).toHaveBeenCalledOnce();
  });

  /**
   * Mail clients prefetch links, so the second request is the ordinary case.
   * It must read as the success it is.
   */
  it("treats an address that was already confirmed as a success", async () => {
    verify.mockResolvedValue({ verified: true, already_verified: true });

    render(<VerifyEmail {...link} />);

    expect(await screen.findByText(/already confirmed/)).toBeVisible();
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("explains an expired or used link, and offers another", async () => {
    verify.mockRejectedValue(new ApiError(403, { message: "Invalid signature." }));

    render(<VerifyEmail {...link} />);

    expect(await screen.findByRole("alert")).toHaveTextContent("expired or has already been used");
    expect(screen.getByRole("link", { name: "Send another link" })).toHaveAttribute(
      "href",
      "/verify-email/sent",
    );
  });

  it("does not blame the link when the server is the problem", async () => {
    verify.mockRejectedValue(new ApiError(500, null));

    render(<VerifyEmail {...link} />);

    const alert = await screen.findByRole("alert");

    expect(alert).not.toHaveTextContent("expired");
  });
});
