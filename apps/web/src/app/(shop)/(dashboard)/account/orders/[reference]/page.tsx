import type { Metadata } from "next";
import Link from "next/link";
import { notFound, unstable_rethrow } from "next/navigation";

import { AddressLines } from "@/components/checkout/address-lines";
import { OrderActions } from "@/components/orders/order-actions";
import { OrderStatusBadge } from "@/components/orders/order-status-badge";
import { OrderTimeline } from "@/components/orders/order-timeline";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { Order, Resource } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";

type Props = PageProps<"/account/orders/[reference]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { reference } = await params;

  return { title: `Order ${reference}`, robots: { index: false, follow: false } };
}

/**
 * One order: where it has got to, what is in it, and what the buyer can do.
 *
 * **Looked up through the buyer's own orders.** `GET /orders/{reference}`
 * searches only the signed-in person's orders, so somebody else's reference is
 * a 404 and draws the same not-found page as one that was never issued. A 403
 * would confirm the order exists (ADR 0011).
 *
 * **The money is what the order came to, not a sum being held.** The design
 * export builds this page around "held by LaravelCommerce" and "confirm it
 * arrived, release the payment". No money is taken yet (ADR 0015, ADR 0031), so
 * the total carries the sentence the checkout already says, and the button
 * says what it does today: it completes the order.
 *
 * A line links back to its listing while the listing still exists, which is
 * the one thing on a receipt that reads the catalogue - and only as a link.
 */
export default async function OrderPage({ params }: Props) {
  const { reference } = await params;

  await requireUser(`/account/orders/${encodeURIComponent(reference)}`);

  const order = await readOrder(reference);

  return (
    <div className="space-y-6">
      <nav aria-label="Breadcrumb">
        <Link
          href="/account/orders"
          className="text-muted-foreground hover:text-foreground text-sm"
        >
          Your orders
        </Link>
      </nav>

      <header className="space-y-2">
        <h1 className="text-2xl font-bold tracking-tight">
          Order <span className="font-mono">{order.reference}</span>
        </h1>
        <p className="text-muted-foreground text-sm">
          {order.shop_name}
          {order.placed_at ? `, placed ${formatDate(order.placed_at)}` : null}
        </p>
        <OrderStatusBadge status={order.status} className="text-sm" />
      </header>

      {/*
       * Beside the account's sidebar the page is narrower than it was on its
       * own, so the total and the address move beside the timeline only on a
       * wide screen.
       */}
      <div className="grid gap-8 xl:grid-cols-[minmax(0,1fr)_18rem] xl:gap-10">
        <div className="space-y-8">
          <section aria-labelledby="progress-heading" className="space-y-4">
            <h2 id="progress-heading" className="font-semibold">
              Where it is
            </h2>
            <OrderTimeline order={order} />
            <OrderActions order={order} />
          </section>

          <section aria-labelledby="items-heading" className="space-y-3">
            <h2 id="items-heading" className="font-semibold">
              What you ordered
            </h2>
            <ul className="bg-card border-border divide-border divide-y rounded-lg border px-4">
              {order.items.map((item) => (
                <li key={item.id} className="flex items-baseline justify-between gap-4 py-3">
                  <div className="min-w-0">
                    {item.product_slug ? (
                      <Link
                        href={`/shops/${order.shop_slug}/products/${item.product_slug}`}
                        className="font-medium hover:underline"
                      >
                        {item.product_name}
                      </Link>
                    ) : (
                      <span className="font-medium">{item.product_name}</span>
                    )}
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

        {/*
         * A div, not an aside. The total and where it is going are the order,
         * not something beside it - and an aside here was a second unnamed
         * complementary landmark next to the account's sidebar, which axe
         * rightly refused (ADR 0033).
         */}
        <div className="space-y-4">
          <div className="bg-card border-border space-y-1 rounded-lg border p-4">
            <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
              Total
            </p>
            <p className="text-2xl font-bold tabular-nums">
              {formatMoney(order.total_minor, order.currency)}
            </p>
            <p className="text-muted-foreground text-xs leading-relaxed">
              No card was charged: taking payment is not built yet.
            </p>
          </div>

          {order.shipping_address ? (
            <section
              aria-labelledby="destination-heading"
              className="bg-card border-border space-y-2 rounded-lg border p-4"
            >
              <h2 id="destination-heading" className="text-sm font-semibold">
                Sending to
              </h2>
              <AddressLines address={order.shipping_address} />
            </section>
          ) : null}
        </div>
      </div>
    </div>
  );
}

async function readOrder(reference: string): Promise<Order> {
  try {
    return (await serverFetch<Resource<Order>>(`/orders/${encodeURIComponent(reference)}`)).data;
  } catch (error) {
    unstable_rethrow(error);

    // Somebody else's reference, or one that was never issued. The same answer
    // for both, by design.
    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }
}
