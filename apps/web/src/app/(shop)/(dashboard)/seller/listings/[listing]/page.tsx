import type { Metadata } from "next";
import Link from "next/link";
import { notFound, redirect, unstable_rethrow } from "next/navigation";

import { ListingDetailsForm } from "@/components/sellers/listing-details-form";
import { ListingImages } from "@/components/sellers/listing-images";
import { ListingPublication } from "@/components/sellers/listing-publication";
import { ListingStatusBadge } from "@/components/sellers/listing-status-badge";
import { ListingVariants } from "@/components/sellers/listing-variants";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { CategoryTree, Product, Resource } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { categoryChoices } from "@/lib/sellers/categories";
import { readShop } from "@/lib/sellers/shop";

/**
 * How many photographs a listing may have. The API's limit, repeated here to
 * say so before somebody is refused, and enforced only there: it answers 409
 * whatever this page believes (ADR 0038).
 */
const IMAGE_LIMIT = 8;

type Props = PageProps<"/seller/listings/[listing]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { listing } = await params;

  return { title: `Listing ${listing}`, robots: { index: false, follow: false } };
}

/**
 * One listing, in the four parts it is edited in: what it says, how it is
 * sold, its photographs, and whether it is on sale (ADR 0038).
 *
 * **Four sections rather than one form.** Each is a separate write to the API -
 * a product, its variants, its images, its publication - and one Save over all
 * of them would have to decide what to do when the third failed after the first
 * two had gone through.
 *
 * **Somebody else's listing is not found.** The API answers 403 for a product
 * that belongs to another shop (ADR 0008); this page draws the same page as for
 * an id that never existed, because to this seller those are the same thing.
 */
export default async function ListingPage({ params }: Props) {
  const { listing: id } = await params;

  await requireUser(`/seller/listings/${encodeURIComponent(id)}`);

  const shop = await readShop();

  if (!shop) {
    redirect("/sell");
  }

  const [listing, categories] = await Promise.all([
    readListing(id),
    serverFetch<Resource<CategoryTree>>("/categories"),
  ]);

  return (
    <div className="max-w-3xl space-y-8">
      <nav aria-label="Breadcrumb">
        <Link
          href="/seller/listings"
          className="text-muted-foreground hover:text-foreground text-sm"
        >
          Listings
        </Link>
      </nav>

      <header className="space-y-2">
        <h1 className="text-2xl font-bold tracking-tight">{listing.name}</h1>
        <ListingStatusBadge status={listing.status} className="text-sm" />
        {listing.is_public ? (
          <p className="text-sm">
            <Link
              href={`/shops/${shop.slug}/products/${listing.slug}`}
              className="text-primary font-medium hover:underline"
            >
              See it as a shopper does
            </Link>
          </p>
        ) : null}
      </header>

      <section aria-labelledby="details-heading" className="space-y-3">
        <h2 id="details-heading" className="font-semibold">
          What it is
        </h2>
        <ListingDetailsForm listing={listing} categories={categoryChoices(categories.data)} />
      </section>

      <section aria-labelledby="options-heading" className="space-y-3">
        <h2 id="options-heading" className="font-semibold">
          How it is sold
        </h2>
        <p className="text-muted-foreground text-sm">
          Each option carries its own price and stock, in {listing.currency}.
        </p>
        <ListingVariants listing={listing} />
      </section>

      <section aria-labelledby="photographs-heading" className="space-y-3">
        <h2 id="photographs-heading" className="font-semibold">
          Photographs
        </h2>
        <ListingImages listing={listing} limit={IMAGE_LIMIT} />
      </section>

      <section aria-labelledby="sale-heading" className="space-y-3">
        <h2 id="sale-heading" className="font-semibold">
          On sale
        </h2>
        <ListingPublication listing={listing} />
      </section>
    </div>
  );
}

async function readListing(id: string): Promise<Product> {
  try {
    return (await serverFetch<Resource<Product>>(`/seller/products/${encodeURIComponent(id)}`))
      .data;
  } catch (error) {
    unstable_rethrow(error);

    // Another shop's listing, or one that was never there. The same answer for
    // both, by design.
    if (error instanceof ApiError && (error.status === 404 || error.status === 403)) {
      notFound();
    }

    throw error;
  }
}
