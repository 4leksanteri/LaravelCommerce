import type { Metadata } from "next";
import Link from "next/link";
import { notFound, redirect, unstable_rethrow } from "next/navigation";

import { AddressLines } from "@/components/checkout/address-lines";
import { OrderStatusBadge } from "@/components/orders/order-status-badge";
import { OrderTimeline } from "@/components/orders/order-timeline";
import { ShopOrderActions } from "@/components/sellers/shop-order-actions";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { Resource, SellerOrder } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";

type Props = PageProps<"/seller/orders/[reference]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { reference } = await params;

  return { title: `Order ${reference}`, robots: { index: false, follow: false } };
}

/**
 * One order, as the shop that received it sees it: who bought it, where it is
 * going, what is in it, and what the shop can do next (ADR 0036).
 *
 * **Looked up through the shop's own orders.** `GET /seller/orders/{reference}`
 * searches only this shop's, so another shop's reference is a 404 and draws the
 * same not-found page as one that was never issued. An account with no shop is
 * refused by the API's `seller` middleware, and sent to open one.
 *
 * **The delivery address is here, and nowhere else the shop is told.** The mail
 * about a new order names the buyer and the items and leaves the address on
 * this page, behind a session (ADR 0035).
 *
 * The same timeline as the buyer's, told from the shop's side.
 */
export default async function ShopOrderPage({ params }: Props) {
  const { reference } = await params;

  await requireUser(`/seller/orders/${encodeURIComponent(reference)}`);

  const order = await readOrder(reference);

  return (
    <div className="space-y-6">
      <nav aria-label="Breadcrumb">
        <Link href="/seller/orders" className="text-muted-foreground hover:text-foreground text-sm">
          Orders
        </Link>
      </nav>

      <header className="space-y-2">
        <h1 className="text-2xl font-bold tracking-tight">
          Order <span className="font-mono">{order.reference}</span>
        </h1>
        <p className="text-muted-foreground text-sm">
          {order.buyer_name}
          {order.placed_at ? `, placed ${formatDate(order.placed_at)}` : null}
        </p>
        <OrderStatusBadge status={order.status} reader="shop" className="text-sm" />
      </header>

      <div className="grid gap-8 xl:grid-cols-[minmax(0,1fr)_18rem] xl:gap-10">
        <div className="space-y-8">
          <section aria-labelledby="progress-heading" className="space-y-4">
            <h2 id="progress-heading" className="font-semibold">
              Where it is
            </h2>
            <OrderTimeline order={order} reader="shop" counterpart={order.buyer_name} />
            <ShopOrderActions order={order} />
          </section>

          <section aria-labelledby="items-heading" className="space-y-3">
            <h2 id="items-heading" className="font-semibold">
              What was ordered
            </h2>
            <ul className="bg-card border-border divide-border divide-y rounded-lg border px-4">
              {order.items.map((item) => (
                <li key={item.id} className="flex items-baseline justify-between gap-4 py-3">
                  <div className="min-w-0">
                    <p className="font-medium">{item.product_name}</p>
                    <p className="text-muted-foreground text-xs">
                      {item.variant_name}, {item.quantity} at{" "}
                      {formatMoney(item.unit_price_minor, order.currency)}
                    </p>
                  </div>
                  <span className="text-sm tabular-nums">
                    {formatMoney(item.line_total_minor, order.currency)}
                  </span>
                </li>
              ))}
            </ul>
          </section>
        </div>

        <div className="space-y-4">
          {order.shipping_address ? (
            <section
              aria-labelledby="destination-heading"
              className="bg-card border-border space-y-2 rounded-lg border p-4"
            >
              <h2 id="destination-heading" className="text-sm font-semibold">
                Send to
              </h2>
              <AddressLines address={order.shipping_address} />
            </section>
          ) : null}

          <div className="bg-card border-border space-y-1 rounded-lg border p-4">
            <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
              Total
            </p>
            <p className="text-2xl font-bold tabular-nums">
              {formatMoney(order.total_minor, order.currency)}
            </p>
            <p className="text-muted-foreground text-xs leading-relaxed">
              Nothing was charged and nothing is paid out yet: payments are not built.
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

async function readOrder(reference: string): Promise<SellerOrder> {
  try {
    return (
      await serverFetch<Resource<SellerOrder>>(`/seller/orders/${encodeURIComponent(reference)}`)
    ).data;
  } catch (error) {
    unstable_rethrow(error);

    // Another shop's order, or one that was never issued. The same answer for
    // both, by design.
    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    // No shop at all: the `seller` middleware's refusal.
    if (error instanceof ApiError && error.status === 403) {
      redirect("/sell");
    }

    throw error;
  }
}
