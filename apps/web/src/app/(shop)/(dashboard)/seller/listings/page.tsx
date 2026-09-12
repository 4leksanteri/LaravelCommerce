import type { Metadata } from "next";
import Link from "next/link";
import { redirect, unstable_rethrow } from "next/navigation";

import { ListingCard } from "@/components/sellers/listing-card";
import { listingStatusLabel } from "@/components/sellers/listing-status-badge";
import { buttonStyles } from "@/components/ui/button";
import { Pagination } from "@/components/ui/pagination";
import { PillLink } from "@/components/ui/pill-link";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { ProductStatus, ShopListings } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { readShop } from "@/lib/sellers/shop";

export const metadata: Metadata = {
  title: "Listings",
  robots: { index: false, follow: false },
};

/**
 * Everything the shop has, drafts included, newest first (ADR 0038).
 *
 * Narrowed by status through the API, as the shop's orders and the review queue
 * are (ADR 0036, ADR 0037): "Drafts" is the half of a catalogue that needs
 * work, and "On sale" is what shoppers can find.
 *
 * A shop that is not approved still gets this page and can still draft. What it
 * cannot do is publish, and the listing's own page says so rather than this one
 * hiding the button.
 */
const STATUSES: ProductStatus[] = ["draft", "published"];

type Props = PageProps<"/seller/listings">;

export default async function ListingsPage({ searchParams }: Props) {
  const params = await searchParams;
  const status = single(params.status);
  const page = single(params.page);

  await requireUser(`/seller/listings${queryOf(status, page)}`);

  const shop = await readShop();

  if (!shop) {
    redirect("/sell");
  }

  const { data: listings, meta } = await readListings(status, page);
  const selected = STATUSES.find((candidate) => candidate === status) ?? null;

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="space-y-1.5">
          <h1 className="text-2xl font-bold tracking-tight">Listings</h1>
          <p className="text-muted-foreground text-sm">
            {selected
              ? `${listingStatusLabel(selected)}: ${meta.total === 1 ? "1 listing" : `${meta.total} listings`}`
              : meta.total === 1
                ? "1 listing, newest first"
                : `${meta.total} listings, newest first`}
          </p>
        </div>

        <Link href="/seller/listings/new" className={buttonStyles({ size: "sm" })}>
          New listing
        </Link>
      </header>

      <nav aria-label="Filter by status">
        <ul className="flex flex-wrap gap-2">
          <li>
            <PillLink href="/seller/listings" active={selected === null}>
              All
            </PillLink>
          </li>
          {STATUSES.map((candidate) => (
            <li key={candidate}>
              <PillLink
                href={`/seller/listings?status=${candidate}`}
                active={selected === candidate}
              >
                {listingStatusLabel(candidate)}
              </PillLink>
            </li>
          ))}
        </ul>
      </nav>

      {listings.length === 0 ? (
        <p className="text-muted-foreground text-sm">
          {meta.total > 0
            ? `There is nothing on page ${meta.current_page}.`
            : selected
              ? "Nothing here."
              : "Nothing listed yet. A listing starts as a draft, and goes on sale when you are ready."}
        </p>
      ) : (
        <ul aria-label="Listings" className="space-y-3">
          {listings.map((listing) => (
            <ListingCard key={listing.id} listing={listing} />
          ))}
        </ul>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(target) =>
          `/seller/listings${queryOf(selected ?? undefined, target > 1 ? String(target) : undefined)}`
        }
      />
    </div>
  );
}

async function readListings(status: string | undefined, page: string | undefined) {
  try {
    return await serverFetch<ShopListings>(`/seller/products${queryOf(status, page)}`);
  } catch (error) {
    unstable_rethrow(error);

    if (error instanceof ApiError && error.status === 422) {
      redirect("/seller/listings");
    }

    throw error;
  }
}

function queryOf(status: ProductStatus | string | undefined, page: string | undefined): string {
  const query = new URLSearchParams();

  if (status) {
    query.set("status", status);
  }

  if (page) {
    query.set("page", page);
  }

  const encoded = query.toString();

  return encoded ? `?${encoded}` : "";
}

function single(value: string | string[] | undefined): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}
