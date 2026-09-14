import type { Metadata } from "next";
import Link from "next/link";
import { notFound, unstable_rethrow } from "next/navigation";
import { cache } from "react";

import { ProductGrid } from "@/components/catalogue/product-grid";
import { Pagination } from "@/components/ui/pagination";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { PublicShop, Resource, ShopListingsPage } from "@/lib/api/types";
import { listingCount } from "@/lib/catalogue/listing-count";

/**
 * A shop, as a place (ADR 0053).
 *
 * ADR 0028 left this open - "whether the storefront exists at all is still
 * open" - and until it did, a shop's name was plain text on every page that
 * showed one, because a link that always led to not-found is worse than no
 * link. This is the page those links were waiting for.
 *
 * **It is where a buyer judges the seller.** In a secondhand marketplace that
 * is most of the decision: what else this shop has, what it says about itself,
 * and what it charges in. The listings are the same public browse a category
 * shows, filtered by shop instead.
 *
 * **The API decides whether the shop exists.** An unapproved shop, a suspended
 * one (ADR 0052) and a slug nobody ever used are the same 404 from
 * `GET /shops/{slug}`, and become this application's not-found page. Nothing
 * here reads a status to decide, and nothing here says which it was: "awaiting
 * review" would tell anybody who guessed a slug that somebody applied under it
 * (ADR 0007).
 */
type Props = PageProps<"/shops/[shopSlug]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { shopSlug } = await params;
  const shop = await readShop(shopSlug);

  return {
    title: shop.shop_name,
    description: shop.description ?? undefined,
  };
}

export default async function ShopPage({ params, searchParams }: Props) {
  const { shopSlug } = await params;
  const page = single((await searchParams).page);

  // The shop first: it is what decides whether this page exists at all, and a
  // 404 from it should not wait on a listings query that will also fail.
  const shop = await readShop(shopSlug);
  const { data, meta } = await readListings(shopSlug, page);

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-8 sm:py-10">
      <nav aria-label="Breadcrumb">
        <ol className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-sm">
          <li>
            <Link href="/search" className="hover:text-foreground">
              Everything
            </Link>
          </li>
          <li className="flex items-center gap-1.5">
            <span aria-hidden="true">/</span>
            <span aria-current="page" className="text-foreground font-medium">
              {shop.shop_name}
            </span>
          </li>
        </ol>
      </nav>

      <header className="space-y-2">
        <h1 className="text-2xl font-bold tracking-tight">{shop.shop_name}</h1>

        {shop.description ? (
          <p className="text-secondary-foreground max-w-2xl text-sm leading-relaxed">
            {shop.description}
          </p>
        ) : null}

        <p className="text-muted-foreground text-sm">
          {listingCount(meta.total)}
          {/*
           * What the shop charges in, said before anybody looks at a price.
           * `PublicShopResource` publishes it for exactly that reason: a
           * basket spanning two currencies is not a number (ADR 0004), and a
           * shopper should know which one they are in.
           */}
          {" . Prices in "}
          {shop.currency}
        </p>
      </header>

      {meta.total === 0 ? (
        <div className="bg-card border-border space-y-3 rounded-lg border p-6">
          <p className="font-semibold">{shop.shop_name} has nothing on sale yet.</p>
          <p className="text-sm">
            <Link href="/search" className="text-primary font-medium hover:underline">
              Browse everything
            </Link>
          </p>
        </div>
      ) : data.length === 0 ? (
        // Past the end: an ordinary race rather than an error (ADR 0022).
        <p className="bg-card border-border text-muted-foreground rounded-lg border p-6 text-sm">
          There is nothing on page {meta.current_page}.{" "}
          <Link href={shopHref(shopSlug)} className="text-primary font-medium hover:underline">
            Back to the first page
          </Link>
        </p>
      ) : (
        // Under an h2, because every card is an h3 (ProductGrid says why).
        <section aria-labelledby="listings-heading">
          <h2 id="listings-heading" className="sr-only">
            Listings
          </h2>
          <ProductGrid products={data} />
        </section>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(target) => shopHref(shopSlug, target)}
        className="pt-4"
      />
    </div>
  );
}

/**
 * Read once per request, through React's `cache`, because `generateMetadata`
 * and the page both need it and `serverFetch` skips Next's fetch cache on
 * purpose (ADR 0028 hit the same thing on the listing's page).
 */
const readShop = cache(async (slug: string): Promise<PublicShop> => {
  try {
    return (await serverFetch<Resource<PublicShop>>(`/shops/${encodeURIComponent(slug)}`)).data;
  } catch (error) {
    unstable_rethrow(error);

    // Unapproved, suspended, or never used. The same answer for all three, by
    // design.
    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }
});

async function readListings(slug: string, page: string | undefined): Promise<ShopListingsPage> {
  const query = page ? `?page=${encodeURIComponent(page)}` : "";

  return serverFetch<ShopListingsPage>(`/shops/${encodeURIComponent(slug)}/products${query}`);
}

function shopHref(slug: string, page?: number): string {
  const address = `/shops/${encodeURIComponent(slug)}`;

  return page && page > 1 ? `${address}?page=${page}` : address;
}

function single(value: string | string[] | undefined): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}
