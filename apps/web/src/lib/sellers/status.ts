import type { SellerStatus } from "@/lib/api/types";

/**
 * Where a shop is in review, in words.
 *
 * One set of words for the badge on the shop's own overview, the sidebar and
 * the review queue's filters, so a shop that is "Awaiting review" to its owner
 * is not "Pending" to the person reviewing it (ADR 0037).
 *
 * The words are the same for both readers, unlike an order's (ADR 0036): an
 * application is one thing being decided, rather than something two sides are
 * each waiting on.
 *
 * Exhaustive over the API's cases, so a fourth status is a type error here
 * rather than a blank beside somebody's application.
 */
export function shopStatusLabel(status: SellerStatus): string {
  switch (status) {
    case "pending":
      return "Awaiting review";
    case "approved":
      return "Open";
    case "rejected":
      return "Not approved";
    default: {
      const unhandled: never = status;

      return unhandled;
    }
  }
}

/**
 * Every status, in the order an application passes through them. A record's
 * keys rather than a hand-written list, so a status the API adds is a type
 * error here until it has a place.
 */
const IN_ORDER: Record<SellerStatus, true> = {
  pending: true,
  approved: true,
  rejected: true,
};

export const SHOP_STATUSES = Object.keys(IN_ORDER) as SellerStatus[];
