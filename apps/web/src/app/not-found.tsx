import Link from "next/link";

import { SiteFooter } from "@/components/shell/site-footer";
import { SiteHeader } from "@/components/shell/site-header";
import { buttonStyles } from "@/components/ui/button";

/**
 * Any address nothing answers.
 *
 * At the root rather than inside `(shop)`, because Next sends every unmatched
 * URL here and a group's own `not-found` only catches `notFound()` thrown
 * inside it. It draws the header itself for the same reason: a 404 with no way
 * back to the shop is a dead end on top of a wrong turn.
 *
 * Also what a listing, shop or order that is not yours looks like. The API
 * answers 404 rather than 403 for those on purpose (ADR 0007, ADR 0011), so this
 * page must not guess at which it was.
 */
export default function NotFound() {
  return (
    <>
      <SiteHeader />
      <main className="mx-auto flex w-full max-w-xl flex-1 flex-col items-start justify-center gap-4 px-4 py-20">
        <p className="text-muted-foreground text-xs font-semibold tracking-widest uppercase">
          Not found
        </p>
        <h1 className="text-2xl font-bold tracking-tight">Nothing lives at this address.</h1>
        <p className="text-muted-foreground text-sm leading-relaxed">
          The link may be out of date, or the listing may have been taken down.
        </p>
        <div className="flex gap-3">
          <Link href="/search" className={buttonStyles()}>
            Browse listings
          </Link>
          <Link href="/" className={buttonStyles({ variant: "secondary" })}>
            Home
          </Link>
        </div>
      </main>
      <SiteFooter />
    </>
  );
}
