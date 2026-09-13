import { paymentLabel, type PaymentState } from "@/lib/orders/payment";
import type { OrderReader } from "@/lib/orders/status";
import { cn } from "@/lib/utils";

/**
 * Where an order's money has got to: a dot and the words.
 *
 * `OrderStatusBadge`'s twin, and deliberately a second badge rather than more
 * words in that one. An order's status is about fulfilment and this is about
 * money, and the whole domain rests on those being different questions
 * (ADR 0015) - a page that merged them would be the first place the difference
 * stopped being visible.
 *
 * The colour is a second signal and never the only one, for the reason
 * `OrderStatusBadge` gives. A record rather than a switch, so a state added to
 * `PaymentState` is a type error here until it has a colour.
 */
const DOT: Record<PaymentState, string> = {
  unpaid: "bg-caution",
  confirming: "bg-caution",
  processing: "bg-primary",
  declined: "bg-destructive",
  abandoned: "bg-muted-foreground",
  paid: "bg-positive",
  transferred: "bg-positive",
  refunded: "bg-muted-foreground",
};

export function PaymentBadge({
  state,
  reader = "buyer",
  className,
}: {
  state: PaymentState;
  reader?: OrderReader;
  className?: string;
}) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs font-semibold", className)}>
      <span aria-hidden="true" className={cn("size-2 shrink-0 rounded-full", DOT[state])} />
      {paymentLabel(state, reader)}
    </span>
  );
}
