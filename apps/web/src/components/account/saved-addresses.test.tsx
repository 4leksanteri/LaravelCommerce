import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import type { Address } from "@/lib/api/types";

import { SavedAddresses } from "./saved-addresses";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({
  useRouter: () => router,
  usePathname: () => "/account/addresses",
}));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const home: Address = {
  id: 12,
  name: "Aino Virtanen",
  line1: "Mannerheimintie 12 A 4",
  line2: null,
  city: "Helsinki",
  region: null,
  postal_code: "00100",
  country: "FI",
  phone: null,
};

const office: Address = { ...home, id: 9, name: "Aino at work", line1: "Keilaranta 1" };

beforeEach(() => {
  router.refresh.mockReset();
});

describe("SavedAddresses", () => {
  it("names each entry's buttons after the entry, so a list of them can be told apart", () => {
    render(<SavedAddresses addresses={[home, office]} />);

    expect(
      screen.getByRole("button", { name: "Edit Aino Virtanen, Mannerheimintie 12 A 4" }),
    ).toBeVisible();
    expect(screen.getByRole("button", { name: "Remove Aino at work, Keilaranta 1" })).toBeVisible();
  });

  it("asks before removing, and sends nothing on a no", async () => {
    const user = userEvent.setup();

    render(<SavedAddresses addresses={[home]} />);
    await user.click(screen.getByRole("button", { name: /^Remove Aino Virtanen/ }));

    expect(screen.getByRole("button", { name: "Yes, remove it" })).toHaveFocus();

    await user.click(screen.getByRole("button", { name: "Keep it" }));

    expect(request).not.toHaveBeenCalled();
  });

  it("removes an entry on a yes, and redraws the book from the API", async () => {
    request.mockResolvedValue(undefined);
    const user = userEvent.setup();

    render(<SavedAddresses addresses={[home]} />);
    await user.click(screen.getByRole("button", { name: /^Remove Aino Virtanen/ }));
    await user.click(screen.getByRole("button", { name: "Yes, remove it" }));

    expect(request).toHaveBeenCalledWith("/addresses/12", { method: "DELETE" });
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  /** One form open at a time, so one save cannot redraw away another's typing. */
  it("opens an entry for editing and holds the other buttons while it is open", async () => {
    const user = userEvent.setup();

    render(<SavedAddresses addresses={[home, office]} />);
    await user.click(screen.getByRole("button", { name: /^Edit Aino Virtanen/ }));

    expect(screen.getByRole("form", { name: "Edit address" })).toBeVisible();
    expect(screen.getByLabelText("Address")).toHaveValue("Mannerheimintie 12 A 4");
    expect(screen.getByRole("button", { name: /^Edit Aino at work/ })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Add an address" })).toBeDisabled();
  });

  it("opens the new-address form straight away when the book is empty", () => {
    render(<SavedAddresses addresses={[]} />);

    expect(screen.getByRole("form", { name: "New address" })).toBeVisible();
  });
});
