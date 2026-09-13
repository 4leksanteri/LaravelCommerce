import type { SellerStatus } from "@/lib/api/types";
import { shopStatusLabel } from "@/lib/sellers/status";
import { cn } from "@/lib/utils";

/**
 * Where a shop is in review: a dot and the words, as an order's badge is.
 *
 * A record, so a status the API adds is a type error here until it has a
 * colour. The words come from `shopStatusLabel`, which the review queue's
 * filters use as well (ADR 0037). The words are always there; the dot is never
 * the only signal.
 */
const DOT: Record<SellerStatus, string> = {
  pending: "bg-caution",
  approved: "bg-positive",

  // Destructive rather than caution (ADR 0052). A suspension is a stop on a
  // business that was trading, not a queue state somebody is waiting through,
  // and amber would read as the latter. Sharing a colour with rejected costs
  // nothing here, because the words are always beside it.
  suspended: "bg-destructive",

  rejected: "bg-destructive",
};

export function ShopStatusBadge({
  status,
  className,
}: {
  status: SellerStatus;
  className?: string;
}) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs font-semibold", className)}>
      <span aria-hidden="true" className={cn("size-2 shrink-0 rounded-full", DOT[status])} />
      {shopStatusLabel(status)}
    </span>
  );
}
