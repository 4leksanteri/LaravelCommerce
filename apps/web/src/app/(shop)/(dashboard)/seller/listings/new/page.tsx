import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";

import { ListingForm } from "@/components/sellers/listing-form";
import { serverFetch } from "@/lib/api/server";
import type { CategoryTree, Resource } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { categoryChoices } from "@/lib/sellers/categories";
import { readShop } from "@/lib/sellers/shop";

export const metadata: Metadata = {
  title: "New listing",
  robots: { index: false, follow: false },
};

/**
 * Writing up something to sell (ADR 0038).
 *
 * **A shop awaiting review can draft.** Publishing is what needs approval, and
 * refusing to let somebody prepare their catalogue while they wait would be a
 * rule the API does not have.
 *
 * The categories are fetched here rather than in the form, because a form is a
 * client component and fetching belongs on the server.
 */
export default async function NewListingPage() {
  await requireUser("/seller/listings/new");

  const shop = await readShop();

  if (!shop) {
    redirect("/sell");
  }

  const categories = await serverFetch<Resource<CategoryTree>>("/categories");

  return (
    <div className="max-w-2xl space-y-6">
      <nav aria-label="Breadcrumb">
        <Link
          href="/seller/listings"
          className="text-muted-foreground hover:text-foreground text-sm"
        >
          Listings
        </Link>
      </nav>

      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">New listing</h1>
        <p className="text-muted-foreground text-sm">
          It is saved as a draft. Nothing is on sale until you say so.
        </p>
      </header>

      <ListingForm categories={categoryChoices(categories.data)} currency={shop.currency} />
    </div>
  );
}
