import type { SellerStatus } from "@/lib/api/types";
import { cn } from "@/lib/utils";

/**
 * Where a shop is in review: a dot and the words, as an order's badge is.
 *
 * A record, so a status the API adds is a type error here until it has words
 * and a colour. The words are always there; the dot is never the only signal.
 */
const STATUS: Record<SellerStatus, { label: string; dot: string }> = {
  pending: { label: "Awaiting review", dot: "bg-caution" },
  approved: { label: "Open", dot: "bg-positive" },
  rejected: { label: "Not approved", dot: "bg-destructive" },
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
      <span aria-hidden="true" className={cn("size-2 shrink-0 rounded-full", STATUS[status].dot)} />
      {STATUS[status].label}
    </span>
  );
}
