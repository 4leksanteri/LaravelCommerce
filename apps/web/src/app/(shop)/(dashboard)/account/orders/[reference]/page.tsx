import type { Metadata } from "next";
import Link from "next/link";
import { notFound, unstable_rethrow } from "next/navigation";

import { AddressLines } from "@/components/checkout/address-lines";
import { Conversation } from "@/components/orders/conversation";
import { OrderActions } from "@/components/orders/order-actions";
import { OrderStatusBadge } from "@/components/orders/order-status-badge";
import { OrderTimeline } from "@/components/orders/order-timeline";
import { PaymentBadge } from "@/components/orders/payment-badge";
import { buttonStyles } from "@/components/ui/button";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { Order, OrderMessagePage, Resource } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";
import { buyerPaymentState } from "@/lib/orders/payment";

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
 * **The money is a second question, beside the total and never inside the
 * status.** Whether a shop has accepted something and whether it has been paid
 * for are different things, which is why `paid` is not an order status
 * (ADR 0015) and why there are two badges here rather than one sentence.
 *
 * **An unpaid order leads back to the card form.** It is the buyer's alone to
 * finish, it holds stock for minutes rather than days, and until ADR 0043 this
 * page showed no way to pay it (ADR 0042). `can_pay` is the API's answer, not a
 * status this page reads a rule from.
 *
 * A line links back to its listing while the listing still exists, which is
 * the one thing on a receipt that reads the catalogue - and only as a link.
 */
export default async function OrderPage({ params }: Props) {
  const { reference } = await params;

  await requireUser(`/account/orders/${encodeURIComponent(reference)}`);

  const order = await readOrder(reference);
  const conversation = await readMessages(reference);

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
            <OrderTimeline order={order} reader="buyer" counterpart={order.shop_name} />
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

          {/*
           * The shop and the buyer, about this order (ADR 0050). On the order
           * rather than in an inbox of its own, because the thing being
           * discussed is always to hand - and there is one thread per order.
           */}
          <section aria-labelledby="messages-heading" className="space-y-3">
            <h2 id="messages-heading" className="font-semibold">
              Messages
            </h2>
            <Conversation
              messages={conversation.data}
              viewer="buyer"
              endpoint={`/orders/${encodeURIComponent(order.reference)}`}
              page={`/account/orders/${encodeURIComponent(order.reference)}`}
              counterpart={order.shop_name}
              unread={order.unread_message_count}
            />
          </section>
        </div>

        {/*
         * A div, not an aside. The total and where it is going are the order,
         * not something beside it - and an aside here was a second unnamed
         * complementary landmark next to the account's sidebar, which axe
         * rightly refused (ADR 0033).
         */}
        <div className="space-y-4">
          <div className="bg-card border-border space-y-2 rounded-lg border p-4">
            <p className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
              Total
            </p>
            <p className="text-2xl font-bold tabular-nums">
              {formatMoney(order.total_minor, order.currency)}
            </p>
            <PaymentBadge state={buyerPaymentState(order)} />
            <p className="text-muted-foreground text-xs leading-relaxed">{moneyNote(order)}</p>
          </div>

          {order.can_pay ? (
            <Link
              href={`/checkout/placed?orders=${encodeURIComponent(order.reference)}`}
              className={buttonStyles({ size: "block" })}
            >
              Pay for this order
            </Link>
          ) : null}

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

/**
 * What happened to the money, in a sentence under the total.
 *
 * The escrow promise is made here rather than on the confirmation alone: a
 * buyer looking at a paid order should be able to see that the shop does not
 * have the money yet and what releases it.
 */
function moneyNote(order: Order): string {
  if (order.refunded_at !== null) {
    return `Refunded on ${formatDate(order.refunded_at)}, to the card it was paid with.`;
  }

  if (order.paid_at !== null) {
    return `Paid on ${formatDate(order.paid_at)}. The shop is paid when you confirm the parcel arrived; until then the money is held here.`;
  }

  if (order.can_pay) {
    return "Nothing has been charged. An order that is not paid for is cancelled after a short while, and its stock goes back.";
  }

  return "Nothing was charged.";
}

/**
 * The first page of the conversation, oldest first.
 *
 * No catch: the order above was found through the buyer's own orders, so this
 * is the same order by the same rule and a failure here is a real one.
 */
async function readMessages(reference: string): Promise<OrderMessagePage> {
  return serverFetch<OrderMessagePage>(`/orders/${encodeURIComponent(reference)}/messages`);
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
