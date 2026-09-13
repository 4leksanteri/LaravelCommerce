import Link from "next/link";

import { OrderStatusBadge } from "@/components/orders/order-status-badge";
import { PaymentBadge } from "@/components/orders/payment-badge";
import type { Order } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";
import { buyerPaymentState } from "@/lib/orders/payment";

/**
 * One order in a list of them, as a card that opens it. Renders the `<li>`, so
 * it goes straight inside a `<ul>`.
 *
 * The link is the title, stretched over the card: the whole card is a target
 * for a pointer, while a screen reader hears one short link name rather than
 * every line on the card read out as a single link.
 *
 * **The money is mentioned only when it wants attention** (ADR 0043). A paid
 * order is the ordinary case and says nothing about it; an unpaid one, a
 * declined card and a refund each say so, because each is something the reader
 * would want to do or know about. A badge on every row would make the rows that
 * matter harder to find, which is the opposite of what a list is for.
 *
 * Used by the list of orders and by the account's overview, which shows the
 * latest few.
 */
export function OrderCard({ order }: { order: Order }) {
  const [first, ...rest] = order.items;
  const what = first
    ? rest.length > 0
      ? `${first.product_name} and ${rest.length} more`
      : first.product_name
    : `Order ${order.reference}`;
  const payment = buyerPaymentState(order);

  return (
    <li className="bg-card border-border hover:border-primary/60 focus-within:ring-ring relative rounded-lg border p-4 transition-colors focus-within:ring-2">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <Link
          href={`/account/orders/${encodeURIComponent(order.reference)}`}
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
        <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <OrderStatusBadge status={order.status} />
          {payment === "paid" ? null : <PaymentBadge state={payment} />}

          {/*
           * Only when something is actually waiting (ADR 0050), for the same
           * reason the payment says nothing about an ordinary paid order: a
           * badge on every row makes the rows that matter harder to find.
           */}
          {order.unread_message_count > 0 ? (
            <span className="bg-accent text-accent-foreground rounded-full px-2 py-0.5 text-xs font-semibold">
              {order.unread_message_count === 1
                ? "1 new message"
                : `${order.unread_message_count} new messages`}
            </span>
          ) : null}
        </span>
        <span className="text-muted-foreground text-xs">
          Reference <code className="font-mono">{order.reference}</code>
        </span>
      </div>
    </li>
  );
}
