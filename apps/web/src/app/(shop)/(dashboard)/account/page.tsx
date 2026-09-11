import type { Metadata } from "next";
import Link from "next/link";

import { ResendVerification } from "@/components/auth/resend-verification";
import { OrderCard } from "@/components/orders/order-card";
import { serverFetch } from "@/lib/api/server";
import type { OrderHistory } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = {
  title: "Your account",
  robots: { index: false, follow: false },
};

/**
 * The account's front page: who is signed in, whether their address is
 * confirmed, and their latest orders.
 *
 * The latest three are the first three of `GET /orders`, which answers twenty
 * a page. The API has no smaller page to ask for, and asking for the list the
 * orders page draws anyway is cheaper than an endpoint of its own.
 *
 * Whether the address is confirmed is shown as the fact it is, from
 * `email_verified_at`. What depends on it - checkout, applying to sell - is
 * each decided by the API where it applies (ADR 0030, ADR 0033).
 */
export default async function AccountPage() {
  const user = await requireUser("/account");
  const { data: orders, meta } = await serverFetch<OrderHistory>("/orders");
  const latest = orders.slice(0, 3);

  return (
    <div className="space-y-8">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Your account</h1>
        <p className="text-muted-foreground text-sm">Signed in as {user.email}</p>
      </header>

      {user.email_verified_at ? null : (
        <section
          aria-labelledby="confirm-heading"
          className="bg-card border-border space-y-3 rounded-lg border p-4"
        >
          <h2 id="confirm-heading" className="font-semibold">
            Confirm your email address
          </h2>
          <p className="text-muted-foreground text-sm leading-relaxed">
            We sent a link to {user.email} when you registered. You need it to check out and to open
            a shop.
          </p>
          <div className="max-w-xs">
            <ResendVerification />
          </div>
        </section>
      )}

      <section aria-labelledby="latest-heading" className="space-y-3">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <h2 id="latest-heading" className="font-semibold">
            Latest orders
          </h2>
          {meta.total > latest.length ? (
            <Link
              href="/account/orders"
              className="text-primary text-sm font-medium hover:underline"
            >
              All {meta.total} orders
            </Link>
          ) : null}
        </div>

        {latest.length === 0 ? (
          <p className="text-muted-foreground text-sm">
            You have not ordered anything yet.{" "}
            <Link href="/search" className="text-primary font-medium hover:underline">
              Browse everything
            </Link>
          </p>
        ) : (
          <ul aria-label="Latest orders" className="space-y-3">
            {latest.map((order) => (
              <OrderCard key={order.reference} order={order} />
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
