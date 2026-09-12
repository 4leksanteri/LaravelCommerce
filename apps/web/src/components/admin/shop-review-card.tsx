import { ShopReviewActions } from "@/components/admin/shop-review-actions";
import { ShopStatusBadge } from "@/components/sellers/shop-status-badge";
import type { Shop } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";

/**
 * One application, with everything the decision rests on. Renders the `<li>`,
 * so it goes straight inside a `<ul>`.
 *
 * **Everything the API sends about the shop is here**, because a reviewer
 * deciding whether somebody may trade should not have to open a second page to
 * read what they wrote. There is no staff endpoint for a single shop, and this
 * card is why one is not needed yet (ADR 0037).
 *
 * A decided application keeps its place with the decision on it: when it was
 * reviewed, and for a rejection the reason that was given, which is what the
 * applicant was sent.
 */
export function ShopReviewCard({ shop }: { shop: Shop }) {
  return (
    <li className="bg-card border-border space-y-3 rounded-lg border p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="text-lg font-semibold">{shop.shop_name}</h2>
        <ShopStatusBadge status={shop.status} />
      </div>

      <p className="text-sm leading-relaxed">
        {shop.description ?? (
          <span className="text-muted-foreground">Nothing written about the shop.</span>
        )}
      </p>

      <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[8rem_minmax(0,1fr)]">
        <dt className="text-muted-foreground">Applied</dt>
        <dd>{formatDate(shop.applied_at)}</dd>

        <dt className="text-muted-foreground">Contact</dt>
        <dd className="break-words">{shop.contact_email}</dd>

        <dt className="text-muted-foreground">Currency</dt>
        <dd>{shop.currency}</dd>

        <dt className="text-muted-foreground">Address</dt>
        <dd className="break-words">
          <code className="font-mono text-xs">/shops/{shop.slug}</code>
        </dd>

        {shop.reviewed_at ? (
          <>
            <dt className="text-muted-foreground">Reviewed</dt>
            <dd>{formatDate(shop.reviewed_at)}</dd>
          </>
        ) : null}

        {shop.rejection_reason ? (
          <>
            <dt className="text-muted-foreground">Reason given</dt>
            <dd className="break-words">{shop.rejection_reason}</dd>
          </>
        ) : null}
      </dl>

      <ShopReviewActions shop={shop} />
    </li>
  );
}
