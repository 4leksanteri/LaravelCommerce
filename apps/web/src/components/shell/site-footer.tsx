import Link from "next/link";

/**
 * Deliberately short.
 *
 * A footer is where dead links accumulate: About, Careers, Press, Help Centre,
 * a newsletter signup and six social icons, none of which have anything behind
 * them. What is here is what exists.
 */
export function SiteFooter() {
  return (
    <footer className="border-border text-muted-foreground mt-16 border-t">
      <div className="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-between gap-4 px-4 py-6 text-xs">
        <p>A marketplace with escrow. Buy from small shops, and pay only when it arrives.</p>

        <nav className="flex gap-4" aria-label="Footer">
          <Link href="/search" className="hover:text-foreground">
            Browse
          </Link>
          <Link href="/sell" className="hover:text-foreground">
            Open a shop
          </Link>
        </nav>
      </div>
    </footer>
  );
}
