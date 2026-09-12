import type { ProductStatus } from "@/lib/api/types";
import { cn } from "@/lib/utils";

/**
 * Whether a listing is on sale, in the same shape as the shop's and the
 * order's badges: a dot and the words, and never the dot alone.
 *
 * **"On sale" rather than "Published"**, because published is what the database
 * calls it and selling is what the seller is doing. A record, so a third status
 * is a type error here rather than a blank beside a listing.
 *
 * Being published is not the same as being visible: an approved shop is needed
 * too, and `is_public` is the API's answer to that (ADR 0038). This badge says
 * what the seller decided; the listing's page says when the shop is in the way.
 */
const STATUS: Record<ProductStatus, { label: string; dot: string }> = {
  draft: { label: "Draft", dot: "bg-caution" },
  published: { label: "On sale", dot: "bg-positive" },
};

export function ListingStatusBadge({
  status,
  className,
}: {
  status: ProductStatus;
  className?: string;
}) {
  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs font-semibold", className)}>
      <span aria-hidden="true" className={cn("size-2 shrink-0 rounded-full", STATUS[status].dot)} />
      {STATUS[status].label}
    </span>
  );
}

export function listingStatusLabel(status: ProductStatus): string {
  return STATUS[status].label;
}
