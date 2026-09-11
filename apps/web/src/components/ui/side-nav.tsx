"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

import { cn } from "@/lib/utils";

/**
 * A column of links, with the page on screen marked.
 *
 * Presentational: labels and addresses, and nothing about what they lead to.
 * A client component for one reason, `usePathname`. A layout is not given the
 * path, and the link that is marked has to be the page actually showing.
 *
 * `exact` is for an overview whose address begins every other address in its
 * section: `/account` is current on `/account` and not on `/account/orders`,
 * where Orders is. Every other link is current on its own address and on
 * anything beneath it, so an order's own page keeps Orders marked. "Beneath"
 * means after a slash, so `/sell` is not current on `/seller`.
 *
 * At phone width the column becomes a row that scrolls sideways, because a
 * column of links is exactly what a phone has no room for beside the page.
 */
export type SideNavItem = { href: string; label: string; exact?: boolean };

export function SideNav({ label, items }: { label: string; items: SideNavItem[] }) {
  const pathname = usePathname();

  return (
    <nav aria-label={label}>
      <ul className="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
        {items.map((item) => {
          const current = item.exact
            ? pathname === item.href
            : pathname === item.href || pathname.startsWith(`${item.href}/`);

          return (
            <li key={item.href} className="shrink-0">
              <Link
                href={item.href}
                aria-current={current ? "page" : undefined}
                className={cn(
                  "focus-visible:ring-ring block rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap outline-none focus-visible:ring-2",
                  current
                    ? "bg-accent text-accent-foreground"
                    : "text-muted-foreground hover:bg-accent hover:text-foreground",
                )}
              >
                {item.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
