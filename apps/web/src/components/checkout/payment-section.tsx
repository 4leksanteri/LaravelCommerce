"use client";

import { Elements, PaymentElement, useElements, useStripe } from "@stripe/react-stripe-js";
import { loadStripe, type Stripe } from "@stripe/stripe-js";
import { useRouter } from "next/navigation";
import { useMemo, useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { CheckoutPayment, Resource } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";

/**
 * Paying for a basket with one card.
 *
 * **The card never reaches this application.** The fields belong to Stripe's
 * own frame; what comes back is a payment method id, and the API confirms each
 * order's intent with it (ADR 0040). Nothing here sees a card number, and
 * nothing here decides an amount: every figure drawn is one the API sent.
 *
 * **The key comes from the API**, with the payment that needs it, rather than
 * being built into this bundle. A stack with no key configured cannot take a
 * payment at all, and says so rather than mounting a form that could not work.
 *
 * **A basket can be several currencies.** Stripe's Element is mounted against
 * the first outstanding order's amount, which decides nothing: it is what the
 * Element displays. Each order is charged its own total, in its own currency,
 * by the API.
 */
export function PaymentSection({ payment }: { payment: CheckoutPayment }) {
  // Loaded once per key. `loadStripe` returns the same promise for a given key,
  // and creating it inside the render would remount the Element on every pass.
  const stripe = useMemo<Promise<Stripe | null> | null>(
    () => (payment.publishable_key ? loadStripe(payment.publishable_key) : null),
    [payment.publishable_key],
  );

  const outstanding = payment.payments.find((one) => one.status !== "succeeded");

  if (!stripe || !outstanding) {
    return (
      <Alert tone="caution">
        This marketplace cannot take a payment at the moment. Your orders are placed, and nothing
        has been charged.
      </Alert>
    );
  }

  return (
    <Elements
      stripe={stripe}
      options={{
        mode: "payment",
        amount: outstanding.amount_minor,
        currency: outstanding.currency.toLowerCase(),
        paymentMethodTypes: ["card"],
      }}
    >
      <CardForm payment={payment} />
    </Elements>
  );
}

/**
 * Inside `Elements`, so `useStripe` and `useElements` have something to answer
 * with.
 *
 * The loop is the point. The API confirms the first order with the card and
 * the rest against the card Stripe kept, and stops if one needs
 * authenticating - so this authenticates it and asks again, which resumes
 * where it left off. Bounded, because a basket has a known number of orders
 * and a loop that cannot end is worse than a payment that does not finish.
 */
function CardForm({ payment }: { payment: CheckoutPayment }) {
  const router = useRouter();
  const stripe = useStripe();
  const elements = useElements();
  const { pending, failure, submit } = useApiSubmit();
  const [problem, setProblem] = useState<string | null>(null);

  const outstanding = payment.payments.filter((one) => one.status !== "succeeded");
  const attempts = outstanding.length + 1;

  async function pay(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setProblem(null);

    if (!stripe || !elements) {
      return;
    }

    await submit(async () => {
      // Stripe's own validation of the fields it owns, before anything is sent.
      const ready = await elements.submit();

      if (ready.error) {
        setProblem(ready.error.message ?? "Check the card details and try again.");

        return;
      }

      const created = await stripe.createPaymentMethod({ elements });

      if (created.error) {
        setProblem(created.error.message ?? "That card could not be used.");

        return;
      }

      let latest = await confirm(created.paymentMethod.id);

      for (let attempt = 0; attempt < attempts && !latest.is_paid; attempt++) {
        const next = latest.payments.find(
          (one) => one.status === "requires_action" && one.client_secret,
        );

        if (!next?.client_secret) {
          break;
        }

        // The card is authenticated here rather than by the API: only the
        // browser can put 3DS in front of the person holding the card.
        const authenticated = await stripe.handleNextAction({
          clientSecret: next.client_secret,
        });

        if (authenticated.error) {
          setProblem(authenticated.error.message ?? "The card could not be authenticated.");
          break;
        }

        latest = await confirm(created.paymentMethod.id);
      }

      router.refresh();
    });
  }

  async function confirm(paymentMethodId: string): Promise<CheckoutPayment> {
    const response = await apiFetch<Resource<CheckoutPayment>>(
      `/checkouts/${encodeURIComponent(payment.checkout_reference)}/payment`,
      {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ payment_method: paymentMethodId }),
      },
    );

    return response.data;
  }

  const refusals = payment.payments.filter((one) => one.failure_reason !== null);

  return (
    <form onSubmit={pay} aria-label="Pay for your orders" className="space-y-4" noValidate>
      {refusals.map((one) => (
        <Alert key={one.order_reference} tone="danger">
          {one.order_reference}: {one.failure_reason}
        </Alert>
      ))}

      <PaymentElement />

      {problem ? <Alert tone="danger">{problem}</Alert> : null}
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <Button type="submit" size="block" disabled={pending || !stripe}>
        {pending ? "Paying..." : payButtonLabel(payment)}
      </Button>

      <p className="text-muted-foreground text-xs leading-relaxed">
        One card pays for the whole basket. Each shop is paid only when you confirm its parcel
        arrived; until then the money is held here.
      </p>
    </form>
  );
}

/**
 * What the button says. A single-shop basket names its figure; several shops in
 * several currencies have no single total, so it does not invent one
 * (ADR 0004).
 */
function payButtonLabel(payment: CheckoutPayment): string {
  const outstanding = payment.payments.filter((one) => one.status !== "succeeded");
  const only = outstanding[0];

  if (outstanding.length === 1 && only) {
    return `Pay ${formatMoney(only.amount_minor, only.currency)}`;
  }

  return `Pay for ${outstanding.length} orders`;
}
