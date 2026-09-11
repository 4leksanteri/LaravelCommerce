import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

import { AddressForm } from "./address-form";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router, usePathname: () => "/checkout" }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

beforeEach(() => {
  router.push.mockReset();
  vi.spyOn(console, "error").mockImplementation(() => {});
});

async function fillRequired() {
  const user = userEvent.setup();

  await user.type(screen.getByLabelText("Recipient"), "Aino Virtanen");
  await user.type(screen.getByLabelText("Address"), "Mannerheimintie 12 A 4");
  await user.type(screen.getByLabelText("City"), "Helsinki");
  await user.type(screen.getByLabelText("Country"), "FI");

  return user;
}

describe("AddressForm", () => {
  it("sends what was filled in, and leaves the empty optional parts out rather than sending blanks", async () => {
    const created = { id: 31, name: "Aino Virtanen" };
    request.mockResolvedValue({ data: created });
    const onCreated = vi.fn();

    render(<AddressForm onCreated={onCreated} />);
    const user = await fillRequired();
    await user.click(screen.getByRole("button", { name: "Save address" }));

    const [, init] = request.mock.calls[0] as [string, RequestInit];

    expect(request.mock.calls[0][0]).toBe("/addresses");
    expect(JSON.parse(String(init.body))).toEqual({
      name: "Aino Virtanen",
      line1: "Mannerheimintie 12 A 4",
      city: "Helsinki",
      country: "FI",
    });
    expect(onCreated).toHaveBeenCalledWith(created);
  });

  it("shows the API's refusal beside the field it is about", async () => {
    request.mockRejectedValue(
      new ApiError(422, {
        message: "The country field must be a two-letter code.",
        errors: { country: ["The country field must be a two-letter code."] },
      }),
    );

    render(<AddressForm onCreated={vi.fn()} />);
    const user = await fillRequired();
    await user.click(screen.getByRole("button", { name: "Save address" }));

    expect(await screen.findByText("The country field must be a two-letter code.")).toBeVisible();
    expect(screen.getByLabelText("Country")).toHaveAttribute("aria-invalid", "true");
    // What was typed is still there to correct.
    expect(screen.getByLabelText("City")).toHaveValue("Helsinki");
  });

  it("offers a way out only when there is somewhere to go back to", () => {
    const { rerender } = render(<AddressForm onCreated={vi.fn()} />);

    expect(screen.queryByRole("button", { name: "Cancel" })).toBeNull();

    rerender(<AddressForm onCreated={vi.fn()} onCancel={vi.fn()} />);

    expect(screen.getByRole("button", { name: "Cancel" })).toBeVisible();
  });
});
