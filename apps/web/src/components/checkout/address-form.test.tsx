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
    const onSaved = vi.fn();

    render(<AddressForm onSaved={onSaved} />);
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
    expect(onSaved).toHaveBeenCalledWith(created);
  });

  it("shows the API's refusal beside the field it is about", async () => {
    request.mockRejectedValue(
      new ApiError(422, {
        message: "The country field must be a two-letter code.",
        errors: { country: ["The country field must be a two-letter code."] },
      }),
    );

    render(<AddressForm onSaved={vi.fn()} />);
    const user = await fillRequired();
    await user.click(screen.getByRole("button", { name: "Save address" }));

    expect(await screen.findByText("The country field must be a two-letter code.")).toBeVisible();
    expect(screen.getByLabelText("Country")).toHaveAttribute("aria-invalid", "true");
    // What was typed is still there to correct.
    expect(screen.getByLabelText("City")).toHaveValue("Helsinki");
  });

  /**
   * A change is a PATCH, where a field that is not sent is left alone, so an
   * optional part somebody cleared is sent as null rather than left out.
   */
  it("changes an entry, sending what was cleared as null", async () => {
    const address = {
      id: 12,
      name: "Aino Virtanen",
      line1: "Mannerheimintie 12 A 4",
      line2: null,
      city: "Helsinki",
      region: null,
      postal_code: "00100",
      country: "FI",
      phone: "+358 40 123 4567",
    };
    request.mockResolvedValue({ data: { ...address, city: "Tampere", phone: null } });
    const onSaved = vi.fn();
    const user = userEvent.setup();

    render(<AddressForm address={address} onSaved={onSaved} />);

    expect(screen.getByRole("form", { name: "Edit address" })).toBeVisible();
    expect(screen.getByLabelText("City")).toHaveValue("Helsinki");

    await user.clear(screen.getByLabelText("City"));
    await user.type(screen.getByLabelText("City"), "Tampere");
    await user.clear(screen.getByLabelText("Phone (optional)"));
    await user.click(screen.getByRole("button", { name: "Save address" }));

    const [path, init] = request.mock.calls[0] as [string, RequestInit];

    expect(path).toBe("/addresses/12");
    expect(init.method).toBe("PATCH");
    expect(JSON.parse(String(init.body))).toEqual({
      name: "Aino Virtanen",
      line1: "Mannerheimintie 12 A 4",
      line2: null,
      city: "Tampere",
      region: null,
      postal_code: "00100",
      country: "FI",
      phone: null,
    });
    expect(onSaved).toHaveBeenCalledWith({ ...address, city: "Tampere", phone: null });
  });

  it("offers a way out only when there is somewhere to go back to", () => {
    const { rerender } = render(<AddressForm onSaved={vi.fn()} />);

    expect(screen.queryByRole("button", { name: "Cancel" })).toBeNull();

    rerender(<AddressForm onSaved={vi.fn()} onCancel={vi.fn()} />);

    expect(screen.getByRole("button", { name: "Cancel" })).toBeVisible();
  });
});
