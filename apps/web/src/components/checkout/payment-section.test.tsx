import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import type { CheckoutPayment } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";

import { PaymentSection } from "./payment-section";

const router = { push: vi.fn(), refresh: vi.fn() };
const createPaymentMethod = vi.fn();
const handleNextAction = vi.fn();
const elementsSubmit = vi.fn();

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));
vi.mock("@stripe/stripe-js", () => ({ loadStripe: () => Promise.resolve({}) }));

/**
 * Stripe's own frame cannot be rendered here, and there would be nothing to
 * assert about it if it could: what this component owns is what it sends and
 * what it does with the answer.
 */
vi.mock("@stripe/react-stripe-js", () => ({
  Elements: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  PaymentElement: () => <div data-testid="card-fields" />,
  useStripe: () => ({ createPaymentMethod, handleNextAction }),
  useElements: () => ({ submit: elementsSubmit }),
}));

const request = vi.mocked(apiFetch);

function session(overrides: Partial<CheckoutPayment> = {}): CheckoutPayment {
  return {
    checkout_reference: "CHK1",
    publishable_key: "pk_test_fake",
    is_paid: false,
    payments: [
      {
        order_reference: "K7M2QXV9RT",
        status: "pending",
        amount_minor: 2499,
        currency: "EUR",
        client_secret: "pi_1_secret",
        failure_reason: null,
        paid_at: null,
      },
    ],
    ...overrides,
  };
}

beforeEach(() => {
  router.refresh.mockReset();
  createPaymentMethod.mockReset().mockResolvedValue({ paymentMethod: { id: "pm_1Card" } });
  handleNextAction.mockReset().mockResolvedValue({});
  elementsSubmit.mockReset().mockResolvedValue({});
});

describe("PaymentSection", () => {
  /** A stack with no key cannot take a payment, and says so (ADR 0040). */
  it("says so when the API sent no publishable key", () => {
    render(<PaymentSection payment={session({ publishable_key: null })} />);

    expect(screen.getByText(/cannot take a payment/)).toBeVisible();
    expect(screen.queryByTestId("card-fields")).not.toBeInTheDocument();
  });

  it("names the amount on the button for a single order", () => {
    render(<PaymentSection payment={session()} />);

    expect(screen.getByRole("button", { name: `Pay ${formatMoney(2499, "EUR")}` })).toBeVisible();
  });

  /**
   * A basket in two currencies has no single total, so the button counts the
   * orders rather than inventing one (ADR 0004).
   */
  it("counts the orders when a basket spans currencies", () => {
    const payment = session();
    payment.payments.push({
      order_reference: "P3QW8ZK5NT",
      status: "pending",
      amount_minor: 3500,
      currency: "GBP",
      client_secret: "pi_2_secret",
      failure_reason: null,
      paid_at: null,
    });

    render(<PaymentSection payment={payment} />);

    expect(screen.getByRole("button", { name: "Pay for 2 orders" })).toBeVisible();
  });

  it("sends the payment method Stripe made, and redraws afterwards", async () => {
    request.mockResolvedValue({ data: session({ is_paid: true }) });
    const user = userEvent.setup();

    render(<PaymentSection payment={session()} />);
    await user.click(screen.getByRole("button", { name: /^Pay / }));

    expect(request).toHaveBeenCalledWith(
      "/checkouts/CHK1/payment",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ payment_method: "pm_1Card" }),
      }),
    );
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  /**
   * Only the browser can put 3DS in front of the person holding the card, so
   * the API stops and this finishes the job - then asks again, which resumes
   * the rest of the basket.
   */
  it("authenticates a card the API could not, and then asks again", async () => {
    const needsAction = session({
      payments: [
        {
          order_reference: "K7M2QXV9RT",
          status: "requires_action",
          amount_minor: 2499,
          currency: "EUR",
          client_secret: "pi_1_secret",
          failure_reason: null,
          paid_at: null,
        },
      ],
    });

    request
      .mockResolvedValueOnce({ data: needsAction })
      .mockResolvedValueOnce({ data: session({ is_paid: true }) });

    const user = userEvent.setup();

    render(<PaymentSection payment={session()} />);
    await user.click(screen.getByRole("button", { name: /^Pay / }));

    expect(handleNextAction).toHaveBeenCalledWith({ clientSecret: "pi_1_secret" });
    expect(request).toHaveBeenCalledTimes(2);
    expect(router.refresh).toHaveBeenCalledOnce();
  });

  it("shows what Stripe said about a card it would not take", async () => {
    const user = userEvent.setup();
    createPaymentMethod.mockResolvedValue({
      error: { message: "Your card number is incomplete." },
    });

    render(<PaymentSection payment={session()} />);
    await user.click(screen.getByRole("button", { name: /^Pay / }));

    expect(await screen.findByText("Your card number is incomplete.")).toBeVisible();
    expect(request).not.toHaveBeenCalled();
  });

  /** A refusal the API recorded, from an earlier attempt. */
  it("shows a refusal the API kept against an order", () => {
    render(
      <PaymentSection
        payment={session({
          payments: [
            {
              order_reference: "K7M2QXV9RT",
              status: "failed",
              amount_minor: 2499,
              currency: "EUR",
              client_secret: "pi_1_secret",
              failure_reason: "Your card was declined.",
              paid_at: null,
            },
          ],
        })}
      />,
    );

    expect(screen.getByText(/Your card was declined\./)).toBeVisible();
  });
});
