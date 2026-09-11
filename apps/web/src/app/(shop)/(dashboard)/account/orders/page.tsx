import type { Metadata } from "next";
import Link from "next/link";

import { OrderCard } from "@/components/orders/order-card";
import { Pagination } from "@/components/ui/pagination";
import { serverFetch } from "@/lib/api/server";
import type { OrderHistory } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = {
  title: "Your orders",
  // Somebody's own purchases. Nothing here is for a search engine.
  robots: { index: false, follow: false },
};

/**
 * Everything the signed-in person has bought, newest first, a page at a time.
 *
 * **One row per order, which is one per shop.** A checkout that spanned three
 * shops is three rows, because it is three orders in three currencies, each of
 * which can go right or wrong on its own. `checkout_reference` could group rows
 * back into the basket they came from, but the list is paged, and a basket
 * split across two pages would read worse than no grouping at all (ADR 0032).
 *
 * **Paged by `meta` rather than by the URL**, as the search page is. `?page=abc`
 * is whatever page the API says it is, and every link is built from its answer.
 *
 * Nothing without a session, so `requireUser` sends a signed-out visitor to
 * sign in and back to the same page.
 */
type Props = PageProps<"/account/orders">;

export default async function OrdersPage({ searchParams }: Props) {
  const requested = single((await searchParams).page);
  const query = requested ? `?page=${encodeURIComponent(requested)}` : "";

  await requireUser(`/account/orders${query}`);

  const { data: orders, meta } = await serverFetch<OrderHistory>(`/orders${query}`);

  return (
    <div className="space-y-6">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Your orders</h1>
        {meta.total > 0 ? (
          <p className="text-muted-foreground text-sm">
            {meta.total === 1 ? "1 order" : `${meta.total} orders`}, newest first
          </p>
        ) : null}
      </header>

      {meta.total === 0 ? (
        <div className="bg-card border-border space-y-3 rounded-lg border p-6">
          <p className="font-semibold">You have not ordered anything yet.</p>
          <Link href="/search" className="text-primary text-sm font-medium hover:underline">
            Browse everything
          </Link>
        </div>
      ) : orders.length === 0 ? (
        // Past the end: `?page=9` of a shorter list. The pagination below still
        // offers the way back.
        <p className="text-muted-foreground text-sm">
          There is nothing on page {meta.current_page}.
        </p>
      ) : (
        <ul aria-label="Orders" className="space-y-3">
          {orders.map((order) => (
            <OrderCard key={order.reference} order={order} />
          ))}
        </ul>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(page) => (page > 1 ? `/account/orders?page=${page}` : "/account/orders")}
      />
    </div>
  );
}

function single(value: string | string[] | undefined): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}
