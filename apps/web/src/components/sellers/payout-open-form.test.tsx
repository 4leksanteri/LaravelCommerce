import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { PayoutCountry } from "@/lib/api/types";

import { PayoutOpenForm } from "./payout-open-form";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const COUNTRIES = ["FI", "IT", "DE"] as PayoutCountry[];

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("PayoutOpenForm", () => {
  /** The list is the API's, and the names are asked of Intl rather than kept here. */
  it("offers the countries the API sent, by name", () => {
    render(<PayoutOpenForm countries={COUNTRIES} />);

    expect(screen.getByRole("option", { name: "Finland" })).toBeVisible();
    expect(screen.getByRole("option", { name: "Italy" })).toBeVisible();
    expect(screen.getByRole("option", { name: "Germany" })).toBeVisible();
  });

  it("sends the country and the acceptance", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<PayoutOpenForm countries={COUNTRIES} />);
    await user.selectOptions(screen.getByLabelText("Where you live"), "IT");
    await user.click(screen.getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: "Open the account" }));

    expect(request).toHaveBeenCalledWith(
      "/seller/payout-account",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ country: "IT", accept_terms: true }),
      }),
    );
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  /**
   * The box is required by the API, not by this form: an unticked one is sent
   * and refused, so the message comes from the place that owns the rule.
   */
  it("puts the API's refusals beside the fields they name", async () => {
    request.mockRejectedValue(
      new ApiError(422, {
        message: "The given data was invalid.",
        errors: {
          country: ["The country field is required."],
          accept_terms: ["The accept terms field must be accepted."],
        },
      }),
    );
    const user = userEvent.setup();

    render(<PayoutOpenForm countries={COUNTRIES} />);
    await user.click(screen.getByRole("button", { name: "Open the account" }));

    expect(await screen.findByText("The country field is required.")).toBeVisible();
    expect(screen.getByText("The accept terms field must be accepted.")).toBeVisible();
    expect(router.refresh).not.toHaveBeenCalled();
  });

  /** A shop that is not approved, or an account that already exists. */
  it("shows the API's reason when an account cannot be opened", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "The shop has not been approved yet." }),
    );
    const user = userEvent.setup();

    render(<PayoutOpenForm countries={COUNTRIES} />);
    await user.click(screen.getByRole("button", { name: "Open the account" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The shop has not been approved yet.",
    );
  });
});
