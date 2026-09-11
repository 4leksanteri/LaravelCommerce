import type { Metadata } from "next";
import Link from "next/link";
import { notFound, unstable_rethrow } from "next/navigation";

import { AddressLines } from "@/components/checkout/address-lines";
import { buttonStyles } from "@/components/ui/button";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { Order, OrderStatus, Resource } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { formatMoney } from "@/lib/money";

export const metadata: Metadata = {
  title: "Orders placed",
  robots: { index: false, follow: false },
};

/**
 * The confirmation, at an address of its own.
 *
 * Not drawn from the checkout response in the browser. That would vanish on a
 * reload, and the checkout page redrawn with an empty basket would unmount it.
 * So checkout sends the buyer here with the orders' references, and this page
 * reads each one back through `GET /orders/{reference}` - which resolves
 * through the buyer's own orders, so somebody else's reference in this URL is
 * a 404 and shows nothing (ADR 0011).
 *
 * At most ten references are read. A checkout makes one order per shop, and a
 * URL is not a way to make this page fan out a hundred requests.
 */
const MOST = 10;

type Props = PageProps<"/checkout/placed">;

export default async function PlacedPage({ searchParams }: Props) {
  const raw = (await searchParams).orders;
  const references = [
    ...new Set(
      (typeof raw === "string" ? raw : "")
        .split(",")
        .map((reference) => reference.trim())
        .filter(Boolean),
    ),
  ].slice(0, MOST);

  await requireUser(`/checkout/placed?orders=${encodeURIComponent(references.join(","))}`);

  if (references.length === 0) {
    notFound();
  }

  const orders = (await Promise.all(references.map(readOrder))).filter(
    (order): order is Order => order !== null,
  );

  if (orders.length === 0) {
    notFound();
  }

  // One basket, one destination: every order froze the same address (ADR 0021).
  const destination = orders[0].shipping_address;

  return (
    <div className="mx-auto w-full max-w-3xl space-y-6 px-4 py-8 sm:py-10">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">
          {orders.length === 1 ? "Your order is placed" : `Your ${orders.length} orders are placed`}
        </h1>
        <p className="text-muted-foreground text-sm">
          {orders.length === 1
            ? "The shop has been told, and will accept it before sending it."
            : "One order with each shop. Each shop has been told, and will accept its order before sending it."}
        </p>
      </header>

      <ul aria-label="Orders placed" className="space-y-3">
        {orders.map((order) => (
          <li
            key={order.reference}
            className="bg-card border-border space-y-3 rounded-lg border p-4"
          >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
              <p className="font-semibold">{order.shop_name}</p>
              <p className="text-muted-foreground text-xs">
                Reference{" "}
                <code className="text-foreground font-mono text-sm">{order.reference}</code>
              </p>
            </div>
            <ul className="text-muted-foreground space-y-1 text-sm">
              {order.items.map((item) => (
                <li key={item.id}>
                  {item.product_name}, {item.variant_name}, quantity {item.quantity}
                </li>
              ))}
            </ul>
            <div className="border-border flex flex-wrap items-baseline justify-between gap-2 border-t pt-3 text-sm">
              <span>{describe(order.status)}</span>
              <span className="font-semibold tabular-nums">
                {formatMoney(order.total_minor, order.currency)}
              </span>
            </div>
          </li>
        ))}
      </ul>

      {destination ? (
        <section aria-labelledby="destination-heading" className="space-y-2">
          <h2 id="destination-heading" className="text-sm font-semibold">
            Sending to
          </h2>
          <div className="bg-card border-border rounded-lg border p-4">
            <AddressLines address={destination} />
          </div>
        </section>
      ) : null}

      {/*
       * The same honesty as the checkout button. Orders are real and move
       * through their states; money is not taken yet (ADR 0015).
       */}
      <p className="text-muted-foreground text-sm leading-relaxed">
        No card was charged: taking payment is not built yet. When it is, each shop&apos;s payment
        will be held until you confirm its parcel arrived.
      </p>

      <Link href="/search" className={buttonStyles({ variant: "secondary" })}>
        Keep browsing
      </Link>
    </div>
  );
}

/** Where an order has got to, in words. Exhaustive over the API's cases. */
function describe(status: OrderStatus): string {
  switch (status) {
    case "pending":
      return "Waiting for the shop to accept it";
    case "accepted":
      return "Accepted by the shop";
    case "shipped":
      return "Sent";
    case "completed":
      return "Completed";
    case "cancelled":
      return "Cancelled";
    default: {
      const unhandled: never = status;

      return unhandled;
    }
  }
}

async function readOrder(reference: string): Promise<Order | null> {
  try {
    return (await serverFetch<Resource<Order>>(`/orders/${encodeURIComponent(reference)}`)).data;
  } catch (error) {
    unstable_rethrow(error);

    // Somebody else's reference, or one that was never issued. The same 404
    // either way, by design, and it simply is not shown.
    if (error instanceof ApiError && error.status === 404) {
      return null;
    }

    throw error;
  }
}
