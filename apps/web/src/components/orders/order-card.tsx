import Link from "next/link";

import { OrderStatusBadge } from "@/components/orders/order-status-badge";
import type { Order } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";

/**
 * One order in a list of them, as a card that opens it. Renders the `<li>`, so
 * it goes straight inside a `<ul>`.
 *
 * The link is the title, stretched over the card: the whole card is a target
 * for a pointer, while a screen reader hears one short link name rather than
 * every line on the card read out as a single link.
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
        <OrderStatusBadge status={order.status} />
        <span className="text-muted-foreground text-xs">
          Reference <code className="font-mono">{order.reference}</code>
        </span>
      </div>
    </li>
  );
}
