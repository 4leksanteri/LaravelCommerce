import Image from "next/image";
import Link from "next/link";

import { ListingStatusBadge } from "@/components/sellers/listing-status-badge";
import type { Product } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";

/**
 * One listing in the shop's own catalogue. Renders the `<li>`, so it goes
 * straight inside a `<ul>`.
 *
 * **It shows each option's own price**, rather than a "from" figure. Which
 * price a listing with three sizes advertises is a rule, and the API answers it
 * for the storefront card (`price_from_minor`); the seller's catalogue has no
 * such field and no business inventing one, and what a seller wants here is
 * what they set anyway (ADR 0038).
 *
 * **A draft's photograph is served against a signature** that expires within
 * the hour (ADR 0016), which `next/image` must not optimise: the optimiser
 * caches by URL and would keep serving it after the signature died. Those are
 * rendered from the signed URL itself.
 */
export function ListingCard({ listing }: { listing: Product }) {
  const cover = listing.images[0] ?? null;
  const shown = listing.variants.slice(0, 3);
  const rest = listing.variants.length - shown.length;

  return (
    <li className="bg-card border-border hover:border-primary/60 focus-within:ring-ring relative flex gap-4 rounded-lg border p-4 transition-colors focus-within:ring-2">
      <div className="bg-muted border-border relative size-20 shrink-0 overflow-hidden rounded-md border">
        {cover ? (
          <Image
            src={cover.url}
            alt=""
            fill
            sizes="80px"
            unoptimized={cover.url.includes("?")}
            className="object-cover"
          />
        ) : (
          <span className="text-muted-foreground flex h-full items-center justify-center px-1 text-center text-[0.625rem]">
            No photograph
          </span>
        )}
      </div>

      <div className="min-w-0 flex-1 space-y-1.5">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <h2 className="min-w-0 font-semibold">
            <Link
              href={`/seller/listings/${listing.id}`}
              className="outline-none after:absolute after:inset-0 after:rounded-lg"
            >
              {listing.name}
            </Link>
          </h2>
          <ListingStatusBadge status={listing.status} />
        </div>

        <p className="text-muted-foreground text-sm">
          {listing.category ? listing.category.name : "No category yet"}
        </p>

        <ul className="text-muted-foreground space-y-0.5 text-xs">
          {shown.map((variant) => (
            <li key={variant.id}>
              {variant.name}: {formatMoney(variant.price_minor, listing.currency)},{" "}
              {variant.stock === 0 ? "none left" : `${variant.stock} in stock`}
            </li>
          ))}
          {rest > 0 ? <li>{rest === 1 ? "1 more option" : `${rest} more options`}</li> : null}
        </ul>
      </div>
    </li>
  );
}
