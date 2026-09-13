import { cn } from "@/lib/utils";

/**
 * What a listing is rated, as stars and as a number (ADR 0047).
 *
 * **The number is the truth and the stars are the impression.** Five shapes
 * cannot say 4.3, so the figure is always rendered beside them - which is the
 * same rule every state badge here follows: the colour is a second signal and
 * never the only one.
 *
 * **Drawn, not typed.** A star glyph is not ASCII, and source here is
 * (root `CLAUDE.md` section 15) - so these are SVG rather than a character that
 * would fail `make charset` and render differently on every platform anyway.
 *
 * **Nothing is drawn for a listing nobody has reviewed.** An empty row of grey
 * stars reads as "rated zero" rather than "not rated", which is a worse lie
 * than saying nothing. Callers who want to say something say it themselves.
 */
export function RatingStars({
  rating,
  count,
  className,
}: {
  rating: number | null;
  count: number;
  className?: string;
}) {
  if (rating === null || count === 0) {
    return null;
  }

  // To the nearest star for the shapes; the exact figure is written out beside
  // them, so the rounding costs nothing a reader cannot see.
  const filled = Math.round(rating);

  return (
    <span className={cn("inline-flex items-center gap-1.5 text-xs", className)}>
      <span aria-hidden="true" className="inline-flex items-center gap-0.5">
        {[1, 2, 3, 4, 5].map((position) => (
          <Star key={position} filled={position <= filled} />
        ))}
      </span>

      <span className="font-medium tabular-nums">{format(rating)}</span>
      <span className="text-muted-foreground">
        ({count === 1 ? "1 review" : `${count} reviews`})
      </span>
    </span>
  );
}

/**
 * One decimal place, and none when it is whole: "4.5" and "4" rather than
 * "4.5" and "4.0". `toFixed` would write the second.
 */
function format(rating: number): string {
  return Number.isInteger(rating) ? String(rating) : rating.toFixed(1);
}

function Star({ filled }: { filled: boolean }) {
  return (
    <svg
      viewBox="0 0 20 20"
      className={cn("size-3.5", filled ? "text-rating" : "text-border")}
      fill="currentColor"
    >
      <path d="M10 1.6l2.6 5.3 5.8.8-4.2 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8L1.6 7.7l5.8-.8z" />
    </svg>
  );
}
