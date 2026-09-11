import type { Metadata } from "next";
import Link from "next/link";
import { notFound, unstable_rethrow } from "next/navigation";
import { cache } from "react";

import { AddToCart } from "@/components/cart/add-to-cart";
import { ProductGallery } from "@/components/catalogue/product-gallery";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { PublicProduct, Resource } from "@/lib/api/types";
import { currentUser } from "@/lib/auth/session";
import { categoryHref } from "@/lib/catalogue/category-href";
import { formatMoney } from "@/lib/money";

/**
 * One listing: what it is, who sells it, what it costs, and the way to buy it.
 *
 * **Public, and only the action needs a session.** A signed-out shopper can
 * read everything here; `currentUser()` decides whether the purchase control is
 * a button or a link to sign in and come back. That is the answer to the
 * question ADR 0023 left open, and it is deliberately narrow: this page is not
 * guarded, its one write is.
 *
 * **The API decides whether the listing exists.** A draft, a deleted listing
 * and an unapproved shop's listing all answer 404 from the storefront endpoint,
 * so all three are this application's not-found page - and none of them says
 * which it was (ADR 0007).
 *
 * The shop's name is text rather than a link. There is no shop page, and the
 * design export's direction may never need one (ADR 0024); a link that always
 * lands on not-found would be the one dead link on a page people act on.
 */
type Props = PageProps<"/shops/[shopSlug]/products/[productSlug]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { shopSlug, productSlug } = await params;
  const product = await readProduct(shopSlug, productSlug);

  return { title: product.name, description: summary(product.description) };
}

export default async function ProductPage({ params }: Props) {
  const { shopSlug, productSlug } = await params;
  const [product, user] = await Promise.all([readProduct(shopSlug, productSlug), currentUser()]);

  // Built from the API's slugs rather than the URL's, so the address a person
  // is sent back to after signing in is the listing's own.
  const here = `/shops/${product.shop_slug}/products/${product.slug}`;

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-8 sm:py-10">
      <nav aria-label="Breadcrumb">
        <ol className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-sm">
          <li>
            <Link href="/search" className="hover:text-foreground">
              Everything
            </Link>
          </li>
          {product.category ? (
            <li className="flex items-center gap-1.5">
              <span aria-hidden="true">/</span>
              <Link href={categoryHref(product.category.slug)} className="hover:text-foreground">
                {product.category.name}
              </Link>
            </li>
          ) : null}
        </ol>
      </nav>

      <div className="grid gap-8 lg:grid-cols-2 lg:gap-12">
        <ProductGallery images={product.images} name={product.name} />

        <div className="space-y-6">
          <header className="space-y-1.5">
            <p className="text-muted-foreground text-sm">
              Sold by <span className="text-foreground font-medium">{product.shop_name}</span>
            </p>
            <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">{product.name}</h1>
          </header>

          {product.in_stock ? (
            <AddToCart
              variants={product.variants}
              currency={product.currency}
              signInHref={user ? null : `/login?next=${encodeURIComponent(here)}`}
            />
          ) : (
            <SoldOut product={product} />
          )}

          <section aria-labelledby="description-heading" className="space-y-2">
            <h2 id="description-heading" className="text-sm font-semibold">
              About this listing
            </h2>
            <p className="text-muted-foreground text-sm leading-relaxed whitespace-pre-line">
              {product.description || "The seller has not described this yet."}
            </p>
          </section>
        </div>
      </div>
    </div>
  );
}

/**
 * Nothing left in any option. Still says what it cost - availability is its
 * own answer, and a price is worth knowing before asking the shop about it
 * (ADR 0024).
 */
function SoldOut({ product }: { product: PublicProduct }) {
  const { price_from_minor: from, price_to_minor: to, currency } = product;

  return (
    <div className="space-y-3">
      {from !== null && to !== null ? (
        <p className="text-muted-foreground text-3xl font-bold tracking-tight tabular-nums">
          {from === to ? null : (
            <>
              <span className="text-base font-medium">from</span>{" "}
            </>
          )}
          {formatMoney(from, currency)}
        </p>
      ) : null}
      <p className="bg-muted border-border rounded-md border px-3 py-2.5 text-sm font-medium">
        Sold out
      </p>
    </div>
  );
}

/**
 * The listing, asked for once per request. Both `generateMetadata` and the page
 * need it, and `serverFetch` deliberately skips Next's fetch cache because it
 * carries a session - so without React's `cache` this would be two requests.
 */
const readProduct = cache(async (shopSlug: string, productSlug: string): Promise<PublicProduct> => {
  const path = `/shops/${encodeURIComponent(shopSlug)}/products/${encodeURIComponent(productSlug)}`;

  try {
    return (await serverFetch<Resource<PublicProduct>>(path)).data;
  } catch (error) {
    unstable_rethrow(error);

    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }
});

/** The first sentence or so, for a search result's snippet. */
function summary(description: string | null | undefined): string | undefined {
  const text = (description ?? "").replace(/\s+/g, " ").trim();

  if (text === "") {
    return undefined;
  }

  return text.length <= 155 ? text : `${text.slice(0, 152).replace(/\s+\S*$/, "")}...`;
}
