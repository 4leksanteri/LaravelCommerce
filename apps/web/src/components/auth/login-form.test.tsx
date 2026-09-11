import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

import { LoginForm } from "./login-form";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const signIn = vi.mocked(apiFetch);

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

async function fillAndSubmit(email: string, password: string) {
  const user = userEvent.setup();

  await user.type(screen.getByLabelText("Email"), email);
  await user.type(screen.getByLabelText("Password"), password);
  await user.click(screen.getByRole("button", { name: "Sign in" }));
}

describe("LoginForm", () => {
  /**
   * ADR 0023 promises this and, until now, nothing checked it: a refusal must
   * not cost somebody what they typed.
   */
  it("keeps the email address when the password was wrong", async () => {
    signIn.mockRejectedValue(
      new ApiError(422, {
        message: "These credentials do not match our records.",
        errors: { email: ["These credentials do not match our records."] },
      }),
    );

    render(<LoginForm redirectTo="/" />);
    await fillAndSubmit("aino@example.test", "not-the-password");

    expect(await screen.findByText("These credentials do not match our records.")).toBeVisible();
    expect(screen.getByLabelText("Email")).toHaveValue("aino@example.test");
    expect(screen.getByLabelText("Email")).toHaveAttribute("aria-invalid", "true");
    expect(router.push).not.toHaveBeenCalled();
  });

  it("posts the credentials and nothing a client could use to decide anything", async () => {
    signIn.mockResolvedValue({ data: {} });

    render(<LoginForm redirectTo="/" />);
    await fillAndSubmit("aino@example.test", "correct-horse-battery");

    expect(signIn).toHaveBeenCalledWith(
      "/auth/login",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          email: "aino@example.test",
          password: "correct-horse-battery",
          remember: false,
        }),
      }),
    );
  });

  /**
   * Refresh before navigating: every Server Component on the next page asks
   * the API who is signed in, and the cached render was for somebody who was
   * not.
   */
  it("refreshes, then goes where the person was heading", async () => {
    signIn.mockResolvedValue({ data: {} });

    render(<LoginForm redirectTo="/orders" />);
    await fillAndSubmit("aino@example.test", "correct-horse-battery");

    expect(router.refresh).toHaveBeenCalledOnce();
    expect(router.push).toHaveBeenCalledWith("/orders");
    expect(router.refresh.mock.invocationCallOrder[0]).toBeLessThan(
      router.push.mock.invocationCallOrder[0],
    );
  });
});
