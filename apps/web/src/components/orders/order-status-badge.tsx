import type { OrderStatus } from "@/lib/api/types";
import { statusLabel } from "@/lib/orders/status";
import { cn } from "@/lib/utils";

/**
 * An order's status: a dot and the words.
 *
 * The colour is a second signal and never the only one. The words are always
 * there, because a dot says nothing to somebody who cannot tell amber from
 * green. Amber is waiting on the shop, blue is under way, green is done and
 * grey is over.
 *
 * A record rather than a switch, so a status the API adds is a type error here
 * until it has a colour.
 */
const DOT: Record<OrderStatus, string> = {
  pending: "bg-caution",
  accepted: "bg-primary",
  shipped: "bg-primary",
  completed: "bg-positive",
  cancelled: "bg-muted-foreground",
};

export function OrderStatusBadge({
  status,
  className,
}: {
  status: OrderStatus;
  className?: string;
}) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs font-semibold", className)}>
      <span aria-hidden="true" className={cn("size-2 shrink-0 rounded-full", DOT[status])} />
      {statusLabel(status)}
    </span>
  );
}
