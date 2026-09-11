"use client";

import { usePathname, useSearchParams } from "next/navigation";

import { SearchInput } from "@/components/shell/search-input";

/**
 * The header's search box, showing the search you are looking at.
 *
 * A client component for one reason: the header lives in a layout, and a
 * layout is not given the query string - only a page is. `useSearchParams` is
 * the way to read it from there, and the header wraps this in a Suspense
 * boundary as the Next docs recommend, with an empty box as the fallback.
 *
 * Only on `/search`. A `?q=` on any other page means something else, or
 * nothing, and should not end up in the search box.
 *
 * `key` is the query itself. `defaultValue` applies only when an input
 * mounts, so without a new key a search made from the results page would
 * leave the previous term in the box after the page changed under it.
 */
export function SearchField() {
  const pathname = usePathname();
  const params = useSearchParams();

  const term = pathname === "/search" ? (params.get("q") ?? "") : "";

  return <SearchInput key={term} defaultValue={term} />;
}
