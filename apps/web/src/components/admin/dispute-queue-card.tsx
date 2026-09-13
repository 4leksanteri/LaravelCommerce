import { DisputeResolveActions } from "@/components/admin/dispute-resolve-actions";
import type { StaffDispute } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";

/**
 * One dispute, with everything the decision rests on. Renders the `<li>`, so it
 * goes straight inside a `<ul>`.
 *
 * **Everything the API sends is here**, because staff are deciding between two
 * people they cannot otherwise see, and there is no endpoint for a single
 * dispute - this card is why one is not needed yet, exactly as `ShopReviewCard`
 * is for an application.
 *
 * The amount is the order's own total in its own currency, formatted and never
 * computed (ADR 0004). The delivery address is deliberately not published to
 * this page: it answers nothing about whether something arrived.
 */
export function DisputeQueueCard({ dispute }: { dispute: StaffDispute }) {
  return (
    <li className="bg-card border-border space-y-3 rounded-lg border p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="text-lg font-semibold">
          Order <span className="font-mono">{dispute.order_reference}</span>
        </h2>
        <span className="font-semibold tabular-nums">
          {formatMoney(dispute.total_minor, dispute.currency)}
        </span>
      </div>

      <p className="text-sm leading-relaxed whitespace-pre-wrap">{dispute.reason}</p>

      <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[8rem_minmax(0,1fr)]">
        <dt className="text-muted-foreground">Buyer</dt>
        <dd className="break-words">{dispute.buyer_name}</dd>

        <dt className="text-muted-foreground">Shop</dt>
        <dd className="break-words">
          {dispute.shop_name} <code className="font-mono text-xs">/shops/{dispute.shop_slug}</code>
        </dd>

        <dt className="text-muted-foreground">Sent</dt>
        <dd>{dispute.shipped_at ? formatDate(dispute.shipped_at) : "Not recorded"}</dd>

        <dt className="text-muted-foreground">Disputed</dt>
        <dd>{dispute.opened_at ? formatDate(dispute.opened_at) : "Not recorded"}</dd>

        {dispute.resolved_at ? (
          <>
            <dt className="text-muted-foreground">Decided</dt>
            <dd>{formatDate(dispute.resolved_at)}</dd>
          </>
        ) : null}

        {dispute.resolution_note ? (
          <>
            <dt className="text-muted-foreground">Reason given</dt>
            <dd className="break-words">{dispute.resolution_note}</dd>
          </>
        ) : null}
      </dl>

      <DisputeResolveActions dispute={dispute} />
    </li>
  );
}
