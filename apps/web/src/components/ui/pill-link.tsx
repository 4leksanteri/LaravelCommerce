import Link from "next/link";
import type { ReactNode } from "react";

import { cn } from "@/lib/utils";

/**
 * One choice in a row of choices - a category to filter by, a subcategory to
 * narrow to.
 *
 * Extracted from the search page's filters when the category page became the
 * second thing to need it (ADR 0019).
 *
 * `aria-current="true"` rather than `"page"`: it marks the chosen item in a set,
 * which is not always the page the person is on. A search filtered to "Audio"
 * is not the Audio page.
 *
 * The caller supplies the `<li>`. A pill is a link, and whether it sits in a
 * list is the row's business.
 */
export function PillLink({
  href,
  active,
  subtle = false,
  children,
}: {
  href: string;
  active: boolean;
  /** For a second row of narrower choices, which should not compete with the first. */
  subtle?: boolean;
  children: ReactNode;
}) {
  return (
    <Link
      href={href}
      aria-current={active ? "true" : undefined}
      className={cn(
        "focus-visible:ring-ring inline-flex h-8 items-center rounded-full border px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-offset-2",
        active
          ? "border-primary bg-primary text-primary-foreground"
          : "border-border bg-card hover:bg-accent hover:text-accent-foreground",
        subtle && !active && "text-muted-foreground",
      )}
    >
      {children}
    </Link>
  );
}
