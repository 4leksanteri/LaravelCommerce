import Link from "next/link";

import { cn } from "@/lib/utils";

/**
 * Page numbers for a set too long for one screen.
 *
 * Presentational: two numbers and a way to build an address. It knows nothing
 * about what is being paged through, so it never imports an API type - the
 * caller hands it `meta.current_page` and `meta.last_page`, which are the API's
 * answer to where this page sits (ADR 0022), rather than anything parsed out
 * of the URL.
 *
 * **Past the end still draws a way back.** `?page=40` of a three-page set is an
 * empty page rather than an error, and usually means something was deleted
 * while somebody was reading. "Previous" then goes to the last real page, not
 * to page 39.
 *
 * Links rather than buttons, because a page of results is a place: it can be
 * bookmarked, opened in a new tab and shared, and none of that works if the
 * page number lives in component state.
 */
type PaginationProps = {
  currentPage: number;
  lastPage: number;
  hrefFor: (page: number) => string;
  className?: string;
};

type Slot = number | "gap-before" | "gap-after";

export function Pagination({ currentPage, lastPage, hrefFor, className }: PaginationProps) {
  if (lastPage <= 1 && currentPage <= lastPage) {
    return null;
  }

  const previous = Math.min(currentPage - 1, lastPage);

  return (
    <nav aria-label="Pagination" className={cn("flex justify-center", className)}>
      <ul className="flex flex-wrap items-center gap-1 text-sm">
        <li>
          {previous >= 1 ? (
            <Link href={hrefFor(previous)} rel="prev" className={cn(item, idle)}>
              Previous
            </Link>
          ) : (
            <span aria-disabled="true" className={cn(item, disabled)}>
              Previous
            </span>
          )}
        </li>

        {slots(currentPage, lastPage).map((slot) =>
          typeof slot === "number" ? (
            <li key={slot}>
              <Link
                href={hrefFor(slot)}
                aria-current={slot === currentPage ? "page" : undefined}
                className={cn(item, slot === currentPage ? current : idle)}
              >
                {slot}
              </Link>
            </li>
          ) : (
            <li key={slot} aria-hidden="true" className="text-muted-foreground px-1">
              &hellip;
            </li>
          ),
        )}

        <li>
          {currentPage < lastPage ? (
            <Link href={hrefFor(currentPage + 1)} rel="next" className={cn(item, idle)}>
              Next
            </Link>
          ) : (
            <span aria-disabled="true" className={cn(item, disabled)}>
              Next
            </span>
          )}
        </li>
      </ul>
    </nav>
  );
}

/**
 * The first page, the last, and the neighbours of the current one, with a gap
 * wherever numbers are skipped. Every page of a hundred would not fit on a
 * phone, and nobody wants page 57 specifically.
 */
function slots(currentPage: number, lastPage: number): Slot[] {
  const shown = new Set([1, lastPage, currentPage - 1, currentPage, currentPage + 1]);
  const pages = [...shown].filter((page) => page >= 1 && page <= lastPage).sort((a, b) => a - b);

  const result: Slot[] = [];

  pages.forEach((page, index) => {
    const before = pages[index - 1];

    if (before !== undefined && page - before > 1) {
      result.push(page < currentPage ? "gap-before" : "gap-after");
    }

    result.push(page);
  });

  return result;
}

const item =
  "inline-flex h-9 min-w-9 items-center justify-center rounded-md border px-3 font-medium tabular-nums outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2";
const idle = "border-border bg-card hover:bg-accent hover:text-accent-foreground";
const current = "border-primary bg-primary text-primary-foreground";
const disabled = "border-border text-muted-foreground opacity-60";
