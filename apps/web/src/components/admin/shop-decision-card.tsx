import Link from "next/link";

import type { DecisionKind, ShopDecision } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";

/**
 * One decision on a shop's record (ADR 0060). Renders the `<li>`, so it goes
 * straight inside a `<ul>`.
 *
 * **The wording lives here rather than in the API**, exactly as `ReportReason`'s
 * does: what to call a decision is presentation. What the API owns is whether
 * it counts against the shop, which is a judgement about its own decision and
 * not something this should re-derive from `kind`.
 *
 * `Record<DecisionKind, string>` rather than a loose map, so a ninth kind added
 * to the enum breaks this build instead of rendering a blank row.
 *
 * **The subject can be gone.** A morph carries no foreign key, so a seller may
 * delete the listing they were punished over - and a shop deleting what it was
 * punished for is itself worth seeing rather than hiding.
 */
const WORDING: Record<DecisionKind, string> = {
  shop_suspended: "Shop suspended",
  shop_reinstated: "Shop reinstated",
  listing_removed: "Listing taken down",
  listing_restored: "Listing restored",
  dispute_refunded: "Dispute refunded to the buyer",
  dispute_released: "Dispute released to the shop",
  appeal_upheld: "Appeal upheld",
  appeal_dismissed: "Appeal dismissed",
};

export function ShopDecisionCard({ decision }: { decision: ShopDecision }) {
  const subject = decision.subject;

  return (
    <li className="bg-card border-border space-y-2 rounded-lg border p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="font-semibold">{WORDING[decision.kind]}</h2>
        <span className="text-muted-foreground text-sm">
          {decision.decided_at ? formatDate(decision.decided_at) : "Not recorded"}
        </span>
      </div>

      <p className="text-muted-foreground text-sm">
        {subject ? (
          <>
            <span className="uppercase">{subject.kind}</span>
            {": "}
            {subject.href ? (
              <Link href={subject.href} className="text-primary font-medium hover:underline">
                {subject.title}
              </Link>
            ) : (
              subject.title
            )}
          </>
        ) : (
          "What this was about has since been deleted."
        )}
      </p>

      {decision.reason ? (
        <blockquote className="border-border text-sm leading-relaxed whitespace-pre-wrap border-l-2 pl-3">
          {decision.reason}
        </blockquote>
      ) : null}
    </li>
  );
}
