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
