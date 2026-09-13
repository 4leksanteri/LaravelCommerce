import type { Metadata } from "next";
import Link from "next/link";
import { notFound, redirect, unstable_rethrow } from "next/navigation";

import { AddressLines } from "@/components/checkout/address-lines";
import { OrderStatusBadge } from "@/components/orders/order-status-badge";
import { OrderTimeline } from "@/components/orders/order-timeline";
import { PaymentBadge } from "@/components/orders/payment-badge";
import { ShopOrderActions } from "@/components/sellers/shop-order-actions";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { Resource, SellerOrder } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";
import { shopPaymentState } from "@/lib/orders/payment";

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

          <div className="bg-card border-border space-y-2 rounded-lg border p-4">
            <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
              What the buyer paid
            </p>
            <p className="text-2xl font-bold tabular-nums">
              {formatMoney(order.total_minor, order.currency)}
            </p>
            <PaymentBadge state={shopPaymentState(order)} reader="shop" />

            {/*
             * What the shop actually gets, and what the marketplace keeps. Both
             * are the API's figures: before the transfer they are what the
             * current rate would leave, and afterwards they are what was taken
             * (ADR 0043). Neither is computed here - the browser formats money
             * and never works it out.
             */}
            {order.payout_amount_minor !== null && order.platform_fee_minor !== null ? (
              <dl className="border-border space-y-1 border-t pt-2 text-xs">
                <div className="flex items-baseline justify-between gap-4">
                  <dt className="text-muted-foreground">Marketplace fee</dt>
                  <dd className="tabular-nums">
                    {formatMoney(order.platform_fee_minor, order.currency)}
                  </dd>
                </div>
                <div className="flex items-baseline justify-between gap-4 font-semibold">
                  <dt>You receive</dt>
                  <dd className="tabular-nums">
                    {formatMoney(order.payout_amount_minor, order.currency)}
                  </dd>
                </div>
              </dl>
            ) : null}

            <p className="text-muted-foreground text-xs leading-relaxed">{payoutNote(order)}</p>
          </div>
        </div>
      </div>
    </div>
  );
}

/**
 * Where this order's money is, in a sentence.
 *
 * A shop is never shown an unpaid order (ADR 0042), so the question is not
 * whether it was paid but whether it has arrived - and saying "held" plainly is
 * what stops a seller believing the money is theirs before the buyer has
 * confirmed anything.
 */
function payoutNote(order: SellerOrder): string {
  if (order.refunded_at !== null) {
    return `Refunded to the buyer on ${formatDate(order.refunded_at)}. Nothing is paid out for this order.`;
  }

  if (order.transferred_at !== null) {
    return `Sent to your payout account on ${formatDate(order.transferred_at)}.`;
  }

  return `Held by the marketplace until ${order.buyer_name} confirms the parcel arrived, and sent to your payout account then, less the fee.`;
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
