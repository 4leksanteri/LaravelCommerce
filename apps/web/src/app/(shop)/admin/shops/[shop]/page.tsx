import type { Metadata } from "next";
import Link from "next/link";

import { ShopDecisionCard } from "@/components/admin/shop-decision-card";
import { ShopStatusBadge } from "@/components/sellers/shop-status-badge";
import { Pagination } from "@/components/ui/pagination";
import { serverFetch } from "@/lib/api/server";
import type { Resource, Shop, ShopRecord } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = {
  title: "Shop record",
  robots: { index: false, follow: false },
};

/**
 * What the platform has decided about one shop (ADR 0060).
 *
 * **Newest first, and it is the only staff list here that is.** The four queues
 * are work to do and put the longest wait first; this is a record, read by
 * somebody about to decide something, and what happened most recently matters
 * most.
 *
 * **It exists because a lifted sanction erases itself.** Reinstating a shop
 * nulls every column that said it was suspended, so without these rows a shop
 * stopped three times reads as one never stopped at all - which is exactly what
 * ADR 0052 and ADR 0054 each said somebody weighing a suspension would want.
 *
 * Reached from the queue card the suspension buttons are on, which is where the
 * question gets asked.
 */
type Props = PageProps<"/admin/shops/[shop]">;

export default async function ShopRecordPage({ params, searchParams }: Props) {
  const { shop: id } = await params;
  const page = single((await searchParams).page);

  const path = `/admin/shops/${encodeURIComponent(id)}`;
  const user = await requireUser(`${path}${page ? `?page=${page}` : ""}`);

  if (!user.can_review_sellers) {
    return (
      <div className="mx-auto w-full max-w-3xl space-y-3 px-4 py-10">
        <h1 className="text-2xl font-bold tracking-tight">Shop record</h1>
        <p className="text-muted-foreground text-sm leading-relaxed">
          Platform staff read what has been decided about a shop. This account is not one, and there
          is nothing here for it.
        </p>
        <p className="text-sm">
          <Link href="/search" className="text-primary font-medium hover:underline">
            Browse the marketplace
          </Link>{" "}
          instead.
        </p>
      </div>
    );
  }

  const [{ data: shop }, record] = await Promise.all([
    serverFetch<Resource<Shop>>(`/admin/sellers/${encodeURIComponent(id)}`),
    serverFetch<ShopRecord>(
      `/admin/sellers/${encodeURIComponent(id)}/decisions${page ? `?page=${encodeURIComponent(page)}` : ""}`,
    ),
  ]);

  const { data: decisions, meta } = record;
  const against = decisions.filter((decision) => decision.counts_against_the_shop).length;

  return (
    <div className="mx-auto w-full max-w-4xl space-y-6 px-4 py-8 sm:py-10">
      <nav aria-label="Breadcrumb">
        <Link href="/admin/shops" className="text-muted-foreground hover:text-foreground text-sm">
          Shops to review
        </Link>
      </nav>

      <header className="space-y-2">
        <h1 className="text-2xl font-bold tracking-tight">{shop.shop_name}</h1>
        <ShopStatusBadge status={shop.status} className="text-sm" />
        <p className="text-muted-foreground text-sm">
          {meta.total === 1 ? "1 decision" : `${meta.total} decisions`}
          {decisions.length > 0
            ? `, ${against === 0 ? "none of them" : against === 1 ? "1 of them" : `${against} of them`} against the shop on this page`
            : ""}
        </p>
      </header>

      {decisions.length === 0 ? (
        <p className="text-muted-foreground text-sm leading-relaxed">
          {meta.total > 0
            ? `There is nothing on page ${meta.current_page}.`
            : "The platform has never decided anything about this shop."}
        </p>
      ) : (
        <ul aria-label="Decisions" className="space-y-4">
          {decisions.map((decision) => (
            <ShopDecisionCard key={decision.id} decision={decision} />
          ))}
        </ul>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(target) => `${path}${target > 1 ? `?page=${target}` : ""}`}
      />
    </div>
  );
}

function single(value: string | string[] | undefined): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}
