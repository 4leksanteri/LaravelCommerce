import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { loadFresh } from "@/lib/navigation";

import { ShopApplicationForm } from "./shop-application-form";

vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));
// jsdom cannot navigate, so the full page load is replaced and asked about.
vi.mock("@/lib/navigation", () => ({ loadFresh: vi.fn() }));

const request = vi.mocked(apiFetch);
const leave = vi.mocked(loadFresh);

const blank = { shopName: "", description: "", contactEmail: "aino@example.test" };

describe("ShopApplicationForm", () => {
  /** Fixed for good once chosen, so nothing is chosen for them. */
  it("asks for a currency rather than choosing one", () => {
    render(<ShopApplicationForm initial={blank} />);

    expect(screen.getByLabelText("Currency")).toHaveValue("");
  });

  it("sends the application, then loads the shop afresh so the header stops offering to open one", async () => {
    request.mockResolvedValue({ data: {} });
    const user = userEvent.setup();

    render(<ShopApplicationForm initial={blank} />);
    await user.type(screen.getByLabelText("Shop name"), "Koskela Cameras");
    await user.selectOptions(screen.getByLabelText("Currency"), "EUR");
    await user.click(screen.getByRole("button", { name: "Send application" }));

    expect(request).toHaveBeenCalledWith(
      "/seller/application",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({
          shop_name: "Koskela Cameras",
          description: "",
          contact_email: "aino@example.test",
          currency: "EUR",
        }),
      }),
    );
    expect(leave).toHaveBeenCalledWith("/seller");
  });

  it("puts the API's refusal beside the field, and keeps what was typed", async () => {
    const message = "The shop name field must be at least 2 characters.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { shop_name: [message] } }));
    const user = userEvent.setup();

    render(<ShopApplicationForm initial={blank} />);
    await user.type(screen.getByLabelText("Shop name"), "K");
    await user.selectOptions(screen.getByLabelText("Currency"), "EUR");
    await user.click(screen.getByRole("button", { name: "Send application" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.getByLabelText("Shop name")).toHaveAttribute("aria-invalid", "true");
    expect(screen.getByLabelText("Shop name")).toHaveValue("K");
    expect(leave).not.toHaveBeenCalled();
  });

  /**
   * Applying again after a rejection. The API keeps the first currency
   * whatever is sent, so the form shows it rather than offering a menu.
   */
  it("shows the currency a first application fixed, and sends that one", async () => {
    request.mockResolvedValue({ data: {} });
    const user = userEvent.setup();

    render(
      <ShopApplicationForm
        initial={{ ...blank, shopName: "Koskela Cameras" }}
        fixedCurrency="SEK"
      />,
    );

    expect(screen.queryByLabelText("Currency")).not.toBeInTheDocument();
    expect(screen.getByText("Swedish krona (SEK)")).toBeVisible();

    await user.click(screen.getByRole("button", { name: "Apply again" }));

    expect(request).toHaveBeenCalledWith(
      "/seller/application",
      expect.objectContaining({ body: expect.stringContaining('"currency":"SEK"') }),
    );
  });
});
