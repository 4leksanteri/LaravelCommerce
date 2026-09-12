import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { PayoutField } from "@/lib/api/types";

import { PayoutDetailsForm } from "./payout-details-form";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

function body(): Record<string, unknown> {
  const [, init] = request.mock.calls[0] as [string, { body: string }];

  return JSON.parse(init.body) as Record<string, unknown>;
}

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
});

describe("PayoutDetailsForm", () => {
  /**
   * The form is whatever the API said was outstanding. Nothing here knows what
   * a Finn or an Italian has to provide (ADR 0031).
   */
  it("draws only the fields the API asked for", () => {
    render(<PayoutDetailsForm due={["first_name", "iban"] as PayoutField[]} />);

    expect(screen.getByLabelText("First name")).toBeVisible();
    expect(screen.getByLabelText("IBAN")).toBeVisible();
    expect(screen.queryByLabelText("Last name")).not.toBeInTheDocument();
    expect(screen.queryByLabelText("ID number")).not.toBeInTheDocument();
  });

  it("sends only what was asked for", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<PayoutDetailsForm due={["first_name", "last_name"] as PayoutField[]} />);
    await user.type(screen.getByLabelText("First name"), "Aino");
    await user.type(screen.getByLabelText("Last name"), "Virtanen");
    await user.click(screen.getByRole("button", { name: "Send to Stripe" }));

    expect(request.mock.calls[0]?.[0]).toBe("/seller/payout-account");
    expect(body()).toEqual({ first_name: "Aino", last_name: "Virtanen" });
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  /** Five inputs, one value, because that is the shape the API takes. */
  it("sends an address as one object", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    render(<PayoutDetailsForm due={["address"] as PayoutField[]} />);
    await user.type(screen.getByLabelText("Street and number"), "Testikatu 1");
    await user.type(screen.getByLabelText("City"), "Helsinki");
    await user.type(screen.getByLabelText("Postcode"), "00100");
    await user.click(screen.getByRole("button", { name: "Send to Stripe" }));

    expect(body()).toEqual({
      address: {
        line1: "Testikatu 1",
        line2: "",
        city: "Helsinki",
        postal_code: "00100",
        state: "",
      },
    });
  });

  it("offers the terms as a box only when Stripe is asking again", async () => {
    request.mockResolvedValue({});
    const user = userEvent.setup();

    const { unmount } = render(<PayoutDetailsForm due={["first_name"] as PayoutField[]} />);
    expect(screen.queryByRole("checkbox")).not.toBeInTheDocument();
    unmount();

    render(<PayoutDetailsForm due={["terms"] as PayoutField[]} />);
    await user.click(screen.getByRole("checkbox"));
    await user.click(screen.getByRole("button", { name: "Send to Stripe" }));

    expect(body()).toEqual({ terms: true });
  });

  it("puts a nested refusal beside the input it names", async () => {
    const message = "The address.line1 field is required.";
    request.mockRejectedValue(
      new ApiError(422, { message, errors: { "address.line1": [message] } }),
    );
    const user = userEvent.setup();

    render(<PayoutDetailsForm due={["address"] as PayoutField[]} />);
    await user.click(screen.getByRole("button", { name: "Send to Stripe" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.getByLabelText("Street and number")).toHaveAttribute("aria-invalid", "true");
  });

  /** The account has not been opened, or Stripe refused the values. */
  it("shows the API's reason when the change is refused", async () => {
    request.mockRejectedValue(
      new ApiError(409, { message: "The shop has not opened a payout account yet." }),
    );
    const user = userEvent.setup();

    render(<PayoutDetailsForm due={["first_name"] as PayoutField[]} />);
    await user.click(screen.getByRole("button", { name: "Send to Stripe" }));

    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The shop has not opened a payout account yet.",
    );
  });
});
