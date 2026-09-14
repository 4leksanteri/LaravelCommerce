import { RatingStars } from "@/components/catalogue/rating-stars";
import { ReportControl } from "@/components/catalogue/report-control";
import type { Review } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";

/**
 * What people who bought a listing said about it (ADR 0047).
 *
 * **No verified badge**, because there is nothing to distinguish: a review
 * cannot exist here without a completed order behind it, so every one of these
 * is a verified purchase and a badge on each would be decoration.
 *
 * **The author arrives shortened.** "Aino V." is the API's doing, not this
 * component's - a product page is public and indexable, and a full name against
 * a purchase is not something a buyer opted into by buying a camera.
 *
 * A rewritten review says so. A reader is entitled to know whether they are
 * looking at a first impression or a corrected one.
 */
export function ReviewList({
  reviews,
  shopSlug,
  productSlug,
}: {
  reviews: Review[];
  /** The listing these belong to, so a report knows what it is about. */
  shopSlug: string;
  productSlug: string;
}) {
  if (reviews.length === 0) {
    return (
      <p className="text-muted-foreground text-sm">
        Nobody has reviewed this yet. Reviews come from people who bought it and confirmed it
        arrived.
      </p>
    );
  }

  return (
    <ul aria-label="Reviews" className="divide-border divide-y">
      {reviews.map((review) => (
        <li key={review.id} className="space-y-1.5 py-4 first:pt-0 last:pb-0">
          <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <RatingStars rating={review.rating} count={1} className="gap-1" />
            <p className="text-muted-foreground text-xs">
              {review.author}
              {review.written_at ? `, ${formatDate(review.written_at)}` : null}
              {review.was_edited ? " (edited)" : null}
            </p>
          </div>

          {review.body ? (
            <p className="text-sm leading-relaxed whitespace-pre-line">{review.body}</p>
          ) : null}

          {/*
           * Reporting one (ADR 0054), and only when the API says this reader
           * may - false for a guest, and false on your own.
           *
           * **A signed-out reader is offered nothing here**, unlike the listing
           * above, and that is deliberate rather than inconsistent. ADR 0028's
           * rule is about a public page whose *one* action needs a session;
           * twenty reviews would mean twenty "sign in to report this" lines
           * under a page somebody came to read. The listing's own control is
           * where signing in is offered.
           */}
          {review.can_report ? (
            <ReportControl
              path={`/shops/${encodeURIComponent(shopSlug)}/products/${encodeURIComponent(productSlug)}/reviews/${review.id}/reports`}
              subject="this review"
            />
          ) : null}
        </li>
      ))}
    </ul>
  );
}
