import type { Order, PaymentStatus, SellerOrder } from "@/lib/api/types";
import type { OrderReader } from "@/lib/orders/status";

/**
 * What has happened to the money for one order, in words, for whoever is
 * reading (ADR 0043).
 *
 * **Money is not the order's status**, and this file existing separately from
 * `status.ts` is that decision showing up in the interface. `paid` was
 * deliberately never added to `OrderStatus`: whether a shop has accepted
 * something and whether it has been paid for are different questions with
 * different answers (ADR 0015). So a page draws both, side by side, rather than
 * one sentence pretending to be both.
 *
 * **The same payment reads differently to each side.** A charge that has been
 * taken is "Paid" to the buyer who paid it and "Paid, held" to the shop, which
 * does not have the money yet and should not be told it does. A transfer is the
 * shop's news and not the buyer's: to them it is still simply paid.
 */
export type PaymentState =
  | "unpaid"
  | "confirming"
  | "processing"
  | "declined"
  | "abandoned"
  | "paid"
  | "transferred"
  | "refunded";

const WORDS: Record<PaymentState, Record<OrderReader, string>> = {
  unpaid: { buyer: "Not paid yet", shop: "Not paid" },
  confirming: { buyer: "Needs confirming", shop: "Being paid" },
  processing: { buyer: "Payment going through", shop: "Being paid" },
  declined: { buyer: "Card declined", shop: "Not paid" },
  abandoned: { buyer: "Payment cancelled", shop: "Not paid" },
  paid: { buyer: "Paid", shop: "Paid, held" },
  transferred: { buyer: "Paid", shop: "Paid out to you" },
  refunded: { buyer: "Refunded", shop: "Refunded to the buyer" },
};

export function paymentLabel(state: PaymentState, reader: OrderReader = "buyer"): string {
  return WORDS[state][reader];
}

/**
 * Where the buyer's money has got to.
 *
 * `refunded_at` and `paid_at` are facts and are read first; the status is what
 * Stripe last said about an intent nothing has happened to yet. A refunded
 * order still has a succeeded payment, because the charge did succeed and the
 * refund is a second event (ADR 0041) - so asking the status first would say
 * "Paid" to somebody who has had their money back.
 */
export function buyerPaymentState(
  order: Pick<Order, "payment_status" | "paid_at" | "refunded_at">,
): PaymentState {
  if (order.refunded_at !== null) {
    return "refunded";
  }

  if (order.paid_at !== null) {
    return "paid";
  }

  return fromStatus(order.payment_status);
}

/**
 * Where the shop's money has got to.
 *
 * A shop is shown no unpaid order at all (ADR 0042), so the interesting
 * question here is not whether it was paid but whether it has arrived: held on
 * the platform until the buyer confirms the parcel, then transferred less the
 * fee, or refunded if the order was called off.
 */
export function shopPaymentState(
  order: Pick<SellerOrder, "paid_at" | "transferred_at" | "refunded_at">,
): PaymentState {
  if (order.refunded_at !== null) {
    return "refunded";
  }

  if (order.transferred_at !== null) {
    return "transferred";
  }

  // Never null in practice, and not asserted to be: a shop told "Paid, held"
  // about an order nobody paid for would be the one mistake worth avoiding.
  return order.paid_at !== null ? "paid" : "unpaid";
}

/**
 * Exhaustive over the API's cases, so a status it adds is a type error here
 * rather than a blank where the money should be.
 */
function fromStatus(status: PaymentStatus | null): PaymentState {
  switch (status) {
    // No intent yet: the moment between checkout writing the order and Stripe
    // answering, which the buyer sees as simply not paid.
    case null:
    case "pending":
      return "unpaid";
    case "requires_action":
      return "confirming";
    case "processing":
      return "processing";
    case "succeeded":
      return "paid";
    case "failed":
      return "declined";
    case "cancelled":
      return "abandoned";
    default: {
      const unhandled: never = status;

      return unhandled;
    }
  }
}
