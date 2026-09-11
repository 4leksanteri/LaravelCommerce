import type { Metadata } from "next";
import Link from "next/link";

import { OrderStatusBadge } from "@/components/orders/order-status-badge";
import { Pagination } from "@/components/ui/pagination";
import { serverFetch } from "@/lib/api/server";
import type { Order, OrderHistory } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";

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
type Props = PageProps<"/orders">;

export default async function OrdersPage({ searchParams }: Props) {
  const requested = single((await searchParams).page);
  const query = requested ? `?page=${encodeURIComponent(requested)}` : "";

  await requireUser(`/orders${query}`);

  const { data: orders, meta } = await serverFetch<OrderHistory>(`/orders${query}`);

  return (
    <div className="mx-auto w-full max-w-3xl space-y-6 px-4 py-8 sm:py-10">
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
            <OrderRow key={order.reference} order={order} />
          ))}
        </ul>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(page) => (page > 1 ? `/orders?page=${page}` : "/orders")}
      />
    </div>
  );
}

/**
 * One order, as a card that opens it.
 *
 * The link is the title, stretched over the card, so the whole card is a
 * target for a pointer while a screen reader hears one short link name rather
 * than every line on the card read out as a single link.
 */
function OrderRow({ order }: { order: Order }) {
  const [first, ...rest] = order.items;
  const what = first
    ? rest.length > 0
      ? `${first.product_name} and ${rest.length} more`
      : first.product_name
    : `Order ${order.reference}`;

  return (
    <li className="bg-card border-border hover:border-primary/60 focus-within:ring-ring relative rounded-lg border p-4 transition-colors focus-within:ring-2">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <Link
          href={`/orders/${encodeURIComponent(order.reference)}`}
          className="min-w-0 font-semibold outline-none after:absolute after:inset-0 after:rounded-lg"
        >
          {what}
        </Link>
        <span className="font-semibold tabular-nums">
          {formatMoney(order.total_minor, order.currency)}
        </span>
      </div>

      <p className="text-muted-foreground mt-1 text-sm">
        {order.shop_name}
        {order.placed_at ? `, placed ${formatDate(order.placed_at)}` : null}
      </p>

      <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
        <OrderStatusBadge status={order.status} />
        <span className="text-muted-foreground text-xs">
          Reference <code className="font-mono">{order.reference}</code>
        </span>
      </div>
    </li>
  );
}

function single(value: string | string[] | undefined): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}
