import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

import { EmailForm } from "./email-form";
import { NameForm } from "./name-form";
import { PasswordForm } from "./password-form";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

beforeEach(() => {
  router.refresh.mockReset();
});

describe("NameForm", () => {
  it("saves the name and redraws the page, which is what updates the sidebar", async () => {
    request.mockResolvedValue({ data: { name: "Aino Virtanen" } });
    const user = userEvent.setup();

    render(<NameForm name="Aino" />);
    await user.type(screen.getByLabelText("Name"), " Virtanen");
    await user.click(screen.getByRole("button", { name: "Save name" }));

    expect(request).toHaveBeenCalledWith(
      "/account",
      expect.objectContaining({ method: "PATCH", body: JSON.stringify({ name: "Aino Virtanen" }) }),
    );
    expect(await screen.findByText("Saved.")).toBeVisible();
    expect(router.refresh).toHaveBeenCalledOnce();
  });
});

describe("EmailForm", () => {
  it("sends the new address with the current password, and says where the link went", async () => {
    request.mockResolvedValue({ data: { email: "aino.v@example.test" } });
    const user = userEvent.setup();

    render(<EmailForm />);
    await user.type(screen.getByLabelText("New email address"), "Aino.V@Example.test");
    await user.type(screen.getByLabelText("Current password"), "correct horse");
    await user.click(screen.getByRole("button", { name: "Change email address" }));

    expect(request).toHaveBeenCalledWith(
      "/account/email",
      expect.objectContaining({
        method: "PUT",
        body: JSON.stringify({ email: "Aino.V@Example.test", current_password: "correct horse" }),
      }),
    );
    // The address as the API stored it, not as it was typed.
    expect(await screen.findByText(/We sent a link to aino\.v@example\.test/)).toBeVisible();
    expect(screen.getByLabelText("Current password")).toHaveValue("");
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("puts a wrong password beside the password, and changes nothing on the page", async () => {
    const message = "That is not your current password.";
    request.mockRejectedValue(
      new ApiError(422, { message, errors: { current_password: [message] } }),
    );
    const user = userEvent.setup();

    render(<EmailForm />);
    await user.type(screen.getByLabelText("New email address"), "aino.v@example.test");
    await user.type(screen.getByLabelText("Current password"), "a guess");
    await user.click(screen.getByRole("button", { name: "Change email address" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.getByLabelText("Current password")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByLabelText("New email address")).toHaveValue("aino.v@example.test");
    expect(router.refresh).not.toHaveBeenCalled();
  });
});

describe("PasswordForm", () => {
  it("sends all three, then empties them and says the other devices are signed out", async () => {
    request.mockResolvedValue(undefined);
    const user = userEvent.setup();

    render(<PasswordForm />);
    await user.type(screen.getByLabelText("Current password"), "correct horse");
    await user.type(screen.getByLabelText("New password"), "a much longer passphrase");
    await user.type(screen.getByLabelText("Confirm new password"), "a much longer passphrase");
    await user.click(screen.getByRole("button", { name: "Change password" }));

    expect(request).toHaveBeenCalledWith(
      "/account/password",
      expect.objectContaining({
        method: "PUT",
        body: JSON.stringify({
          current_password: "correct horse",
          password: "a much longer passphrase",
          password_confirmation: "a much longer passphrase",
        }),
      }),
    );
    expect(await screen.findByText(/Anywhere else this account was signed in/)).toBeVisible();
    expect(screen.getByLabelText("New password")).toHaveValue("");
  });

  it("puts the API's rule beside the new password", async () => {
    const message = "The password field must be at least 12 characters.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { password: [message] } }));
    const user = userEvent.setup();

    render(<PasswordForm />);
    await user.type(screen.getByLabelText("Current password"), "correct horse");
    await user.type(screen.getByLabelText("New password"), "short");
    await user.type(screen.getByLabelText("Confirm new password"), "short");
    await user.click(screen.getByRole("button", { name: "Change password" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.getByLabelText("New password")).toHaveAttribute("aria-invalid", "true");
  });
});
