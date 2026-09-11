import type { ComponentProps } from "react";

/**
 * The header's search box, as markup only.
 *
 * Split from `SearchField` so the header can render it as the Suspense
 * fallback - the same box, empty - without that fallback being a client
 * component itself. `name="q"` is the whole contract with the form around it:
 * the browser serialises it into `/search?q=...` with no JavaScript involved.
 */
export function SearchInput(props: Omit<ComponentProps<"input">, "name" | "type">) {
  return (
    <input
      type="search"
      name="q"
      placeholder="Search for a camera, a lens, a record player"
      aria-label="Search listings"
      className="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/30 h-9 w-full rounded-md border px-3 text-sm outline-none focus-visible:ring-2"
      {...props}
    />
  );
}
