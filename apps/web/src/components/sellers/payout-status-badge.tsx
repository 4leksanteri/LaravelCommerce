import type { PayoutStatus } from "@/lib/api/types";
import { cn } from "@/lib/utils";

/**
 * Where the shop stands with getting paid, in the same shape as the shop's and
 * the listing's badges: a dot and the words, and never the dot alone.
 *
 * The status is derived by the API from what Stripe last said, never stored
 * (ADR 0031), so these are five answers rather than five things this page
 * decides. A record, so a sixth is a type error here until it has words.
 *
 * "Ready" rather than "active": what a seller wants to know is whether money
 * will reach them, not which word Stripe's schema uses.
 */
const STATUS: Record<PayoutStatus, { label: string; dot: string }> = {
  not_started: { label: "Not set up", dot: "bg-muted-foreground" },
  action_required: { label: "Needs your details", dot: "bg-caution" },
  in_review: { label: "Being checked", dot: "bg-primary" },
  active: { label: "Ready", dot: "bg-positive" },
  rejected: { label: "Not approved", dot: "bg-destructive" },
};

export function PayoutStatusBadge({
  status,
  className,
}: {
  status: PayoutStatus;
  className?: string;
}) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs font-semibold", className)}>
      <span aria-hidden="true" className={cn("size-2 shrink-0 rounded-full", STATUS[status].dot)} />
      {STATUS[status].label}
    </span>
  );
}

export function payoutStatusLabel(status: PayoutStatus): string {
  return STATUS[status].label;
}
