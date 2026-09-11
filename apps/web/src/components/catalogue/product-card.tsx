import Image from "next/image";
import Link from "next/link";

import type { PublicProduct } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";

/**
 * A listing in a grid.
 *
 * Two rules from the design export's own notes, and both are about who the
 * buyer is dealing with. **The price is the heaviest weight on the card**, and
 * **the shop's name is always shown**, because somebody here is buying from a
 * shop, not from the marketplace - a card without it reads like a warehouse.
 *
 * What the export's cards show and these do not: a star rating, a condition
 * badge, a struck-through old price and "free shipping". There are no reviews,
 * no condition field, no compare-at price and no shipping in the API, and a
 * card that invented any of them would be the most-viewed lie on the site.
 *
 * The price shown is the API's answer, not a minimum computed here. Which
 * figure a listing with several sizes advertises is a rule, and the browser's
 * copy of a rule is the one that drifts (root `CLAUDE.md` section 4).
 */
export function ProductCard({ product }: { product: PublicProduct }) {
  const cover = product.images[0] ?? null;
  const href = `/shops/${product.shop_slug}/products/${product.slug}`;

  return (
    <article className="group relative flex flex-col gap-2.5">
      <div className="bg-muted border-border relative aspect-square overflow-hidden rounded-lg border">
        {cover ? (
          <Image
            src={cover.url}
            // A seller who wrote no alt text still has a named product, and the
            // name is a better description than an empty string - which would
            // tell a screen reader the photograph is decorative.
            alt={cover.alt_text ?? product.name}
            fill
            sizes="(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw"
            className="object-cover transition-transform duration-300 group-hover:scale-[1.02]"
          />
        ) : (
          <div className="text-muted-foreground flex h-full items-center justify-center text-xs">
            No photograph yet
          </div>
        )}

        {!product.in_stock ? (
          <span className="bg-foreground text-background absolute top-2 left-2 rounded-sm px-1.5 py-0.5 text-xs font-medium">
            Sold out
          </span>
        ) : null}
      </div>

      <div className="space-y-1">
        <p className="text-muted-foreground truncate text-xs">{product.shop_name}</p>

        <h3 className="line-clamp-2 text-sm leading-snug font-medium">
          {/*
           * The whole card is the link, by stretching this one over it rather
           * than wrapping the article in an anchor: a screen reader then reads
           * one link named for the product, instead of one link containing a
           * photograph, a shop, a title and a price.
           */}
          <Link
            href={href}
            className="focus-visible:ring-ring rounded-sm outline-none after:absolute after:inset-0 focus-visible:ring-2 focus-visible:ring-offset-2"
          >
            {product.name}
          </Link>
        </h3>

        <Price product={product} />
      </div>
    </article>
  );
}

function Price({ product }: { product: PublicProduct }) {
  const { price_from_minor: from, price_to_minor: to, currency } = product;

  if (from === null || to === null) {
    return null;
  }

  return (
    <p className="text-base font-bold tabular-nums">
      {/*
       * A real space rather than a margin. The margin separates them for the
       * eye only, and a screen reader joining two adjacent elements reads
       * "fromDKK 950.00" as one word.
       */}
      {from === to ? null : (
        <>
          <span className="text-muted-foreground text-xs font-medium">from</span>{" "}
        </>
      )}
      {formatMoney(from, currency)}
    </p>
  );
}
