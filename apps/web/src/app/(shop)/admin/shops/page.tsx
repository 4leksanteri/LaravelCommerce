import type { Metadata } from "next";
import Link from "next/link";
import { redirect, unstable_rethrow } from "next/navigation";

import { ShopReviewCard } from "@/components/admin/shop-review-card";
import { Pagination } from "@/components/ui/pagination";
import { PillLink } from "@/components/ui/pill-link";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { SellerStatus, ShopPage } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { SHOP_STATUSES, shopStatusLabel } from "@/lib/sellers/status";

export const metadata: Metadata = {
  title: "Shops to review",
  robots: { index: false, follow: false },
};

/**
 * The review queue: applications waiting on staff, and the decisions already
 * taken (ADR 0037).
 *
 * **Oldest first, which is the API's order**, so the person who has waited
 * longest is at the top. The filters narrow it through the API the way the
 * shop's own order queue is narrowed (ADR 0036), and an unknown status is a 422
 * that sends the reader back to the whole queue.
 *
 * **One page, and no staff area around it.** This is the only page staff have,
 * and a sidebar and a layout for one page would be a shell built before there
 * is anything to put in it. It is reached from a link in the header, drawn from
 * `can_review_sellers`.
 *
 * **The refusal is explained rather than hidden.** Somebody who is not staff is
 * told what this page is, because a 403 is "not allowed" and saying so is
 * honest (ADR 0008). The API refuses the queue itself regardless of what this
 * page draws.
 */
type Props = PageProps<"/admin/shops">;

export default async function ShopReviewPage({ searchParams }: Props) {
  const params = await searchParams;
  const status = single(params.status);
  const page = single(params.page);

  const user = await requireUser(`/admin/shops${queryOf(status, page)}`);

  if (!user.can_review_sellers) {
    return (
      <div className="mx-auto w-full max-w-3xl space-y-3 px-4 py-10">
        <h1 className="text-2xl font-bold tracking-tight">Shops to review</h1>
        <p className="text-muted-foreground text-sm leading-relaxed">
          Platform staff review shop applications. This account is not one, and there is nothing
          here for it.
        </p>
        <p className="text-sm">
          <Link href="/seller" className="text-primary font-medium hover:underline">
            Your own shop
          </Link>{" "}
          is where what you sell lives.
        </p>
      </div>
    );
  }

  const { data: shops, meta } = await readQueue(status, page);
  const selected = SHOP_STATUSES.find((candidate) => candidate === status) ?? null;

  return (
    <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8 sm:py-10">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Shops to review</h1>
        <p className="text-muted-foreground text-sm">
          {selected
            ? `${shopStatusLabel(selected)}: ${meta.total === 1 ? "1 shop" : `${meta.total} shops`}`
            : meta.total === 1
              ? "1 shop, the longest wait first"
              : `${meta.total} shops, the longest wait first`}
        </p>
      </header>

      <nav aria-label="Filter by status">
        <ul className="flex flex-wrap gap-2">
          <li>
            <PillLink href="/admin/shops" active={selected === null}>
              All
            </PillLink>
          </li>
          {SHOP_STATUSES.map((candidate) => (
            <li key={candidate}>
              <PillLink href={`/admin/shops?status=${candidate}`} active={selected === candidate}>
                {shopStatusLabel(candidate)}
              </PillLink>
            </li>
          ))}
        </ul>
      </nav>

      {shops.length === 0 ? (
        <p className="text-muted-foreground text-sm">
          {meta.total > 0
            ? `There is nothing on page ${meta.current_page}.`
            : selected === "pending"
              ? "Nothing is waiting. Every application has been decided."
              : "No shops here."}
        </p>
      ) : (
        <ul aria-label="Shops" className="space-y-4">
          {shops.map((shop) => (
            <ShopReviewCard key={shop.id} shop={shop} />
          ))}
        </ul>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(target) =>
          `/admin/shops${queryOf(selected ?? undefined, target > 1 ? String(target) : undefined)}`
        }
      />
    </div>
  );
}

async function readQueue(status: string | undefined, page: string | undefined) {
  try {
    return await serverFetch<ShopPage>(`/admin/sellers${queryOf(status, page)}`);
  } catch (error) {
    unstable_rethrow(error);

    // Only a hand-edited address produces one, and the whole queue is a better
    // answer than an error page.
    if (error instanceof ApiError && error.status === 422) {
      redirect("/admin/shops");
    }

    throw error;
  }
}

function queryOf(status: SellerStatus | string | undefined, page: string | undefined): string {
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
