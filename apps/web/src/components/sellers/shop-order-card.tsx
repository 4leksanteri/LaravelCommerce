import Link from "next/link";

import { OrderStatusBadge } from "@/components/orders/order-status-badge";
import { PaymentBadge } from "@/components/orders/payment-badge";
import type { SellerOrder } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";
import { shopPaymentState } from "@/lib/orders/payment";

/**
 * One order in a shop's queue, as a card that opens it. Renders the `<li>`, so
 * it goes straight inside a `<ul>`.
 *
 * The buyer's own card, `OrderCard`, turned round: who bought it and where it
 * is going instead of which shop, and the status in the shop's words - "to
 * accept", "to send" - because a shop's list of orders is a list of things to
 * do (ADR 0036). Two cards rather than one with options, because the two
 * resources are different shapes and share only the layout.
 *
 * **What the shop receives is on the card, and the fee is not** (ADR 0043). A
 * queue is scanned rather than read, and the useful figure is the one that
 * arrives; the arithmetic behind it belongs on the order's own page. As on the
 * buyer's card, the payment is named only when it is not the ordinary "paid and
 * held" - every row here is paid, so saying it on each would be noise.
 *
 * The link is the title, stretched over the card, for the reason OrderCard
 * gives.
 */
export function ShopOrderCard({ order }: { order: SellerOrder }) {
  const [first, ...rest] = order.items;
  const what = first
    ? rest.length > 0
      ? `${first.product_name} and ${rest.length} more`
      : first.product_name
    : `Order ${order.reference}`;
  const city = order.shipping_address?.city;
  const payment = shopPaymentState(order);

  return (
    <li className="bg-card border-border hover:border-primary/60 focus-within:ring-ring relative rounded-lg border p-4 transition-colors focus-within:ring-2">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <Link
          href={`/seller/orders/${encodeURIComponent(order.reference)}`}
          className="min-w-0 font-semibold outline-none after:absolute after:inset-0 after:rounded-lg"
        >
          {what}
        </Link>
        <span className="text-right">
          <span className="block font-semibold tabular-nums">
            {formatMoney(order.total_minor, order.currency)}
          </span>
          {order.payout_amount_minor !== null ? (
            <span className="text-muted-foreground block text-xs tabular-nums">
              you receive {formatMoney(order.payout_amount_minor, order.currency)}
            </span>
          ) : null}
        </span>
      </div>

      <p className="text-muted-foreground mt-1 text-sm">
        {order.buyer_name}
        {city ? `, ${city}` : null}
        {order.placed_at ? `, placed ${formatDate(order.placed_at)}` : null}
      </p>

      <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
        <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <OrderStatusBadge status={order.status} reader="shop" />
          {payment === "paid" ? null : <PaymentBadge state={payment} reader="shop" />}
        </span>
        <span className="text-muted-foreground text-xs">
          Reference <code className="font-mono">{order.reference}</code>
        </span>
      </div>
    </li>
  );
}
