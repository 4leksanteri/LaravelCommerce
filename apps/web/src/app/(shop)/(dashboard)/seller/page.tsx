import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import type { ReactNode } from "react";

import { ShopStatusBadge } from "@/components/sellers/shop-status-badge";
import { Alert } from "@/components/ui/alert";
import { buttonStyles } from "@/components/ui/button";
import { serverFetch } from "@/lib/api/server";
import type { Shop, ShopListings, ShopOrders } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { formatDate } from "@/lib/dates";
import { readShop } from "@/lib/sellers/shop";

export const metadata: Metadata = {
  title: "Your shop",
  robots: { index: false, follow: false },
};

/**
 * The shop's front page: where it stands, and what it has.
 *
 * Every state has something to say. Waiting on review, the shop's details can
 * still be changed. Rejected, the reason is the one thing needed to apply
 * again. Open, what it lists and what it has sold. An account with no shop has
 * nothing here, and is sent to open one.
 *
 * The export's dashboard leads with money held in escrow, money released and a
 * rating. None of them exists yet - no money is taken (ADR 0015), and there are
 * no reviews - so the figures are the two the API has: listings and orders.
 */
export default async function ShopOverviewPage() {
  await requireUser("/seller");

  const shop = await readShop();

  if (!shop) {
    redirect("/sell");
  }

  return (
    <div className="space-y-8">
      <header className="space-y-2">
        <h1 className="text-2xl font-bold tracking-tight">{shop.shop_name}</h1>
        <ShopStatusBadge status={shop.status} className="text-sm" />
      </header>

      <Standing shop={shop} />

      {/* `is_public` is the API's answer to whether the shop is trading. */}
      {shop.is_public ? <Figures /> : null}

      <section aria-labelledby="details-heading" className="space-y-3">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
          <h2 id="details-heading" className="font-semibold">
            Details
          </h2>
          {shop.can_edit ? (
            <Link
              href="/seller/settings"
              className="text-primary text-sm font-medium hover:underline"
            >
              Change them
            </Link>
          ) : null}
        </div>
        <dl className="bg-card border-border divide-border divide-y rounded-lg border">
          <Detail term="Contact email">{shop.contact_email}</Detail>
          <Detail term="Currency">{shop.currency}</Detail>
          <Detail term="About the shop">
            {shop.description ?? (
              <span className="text-muted-foreground">Nothing written yet.</span>
            )}
          </Detail>
        </dl>
      </section>
    </div>
  );
}

/** What the shop's status means for its owner. Exhaustive over the API's cases. */
function Standing({ shop }: { shop: Shop }) {
  switch (shop.status) {
    case "pending":
      return (
        <Alert tone="info">
          Staff read every application before a shop can open. Yours was sent on{" "}
          {formatDate(shop.applied_at)}, and this page will say when they have decided. You can
          change the shop&apos;s details while you wait.
        </Alert>
      );

    case "rejected":
      return (
        <div className="space-y-3">
          <Alert tone="danger">
            Your application was not approved.
            {shop.rejection_reason ? ` The reason given: ${shop.rejection_reason}` : null}
          </Alert>
          <Link href="/sell" className={buttonStyles({ size: "sm" })}>
            Apply again
          </Link>
        </div>
      );

    case "approved":
      return (
        <Alert tone="positive">
          Your shop is open. Shoppers can find everything it has published.
        </Alert>
      );

    default: {
      const unhandled: never = shop.status;

      return unhandled;
    }
  }
}

/**
 * How many listings the shop has, at any status, and how many orders it has
 * taken: the `total` of the first page of each list. Both have their own pages
 * now (ADR 0036, ADR 0038), so each figure leads to one.
 */
async function Figures() {
  const [listings, orders] = await Promise.all([
    serverFetch<ShopListings>("/seller/products"),
    serverFetch<ShopOrders>("/seller/orders"),
  ]);

  return (
    <dl className="grid gap-3 sm:grid-cols-2">
      <Figure term="Listings" value={listings.meta.total} href="/seller/listings" />
      <Figure term="Orders" value={orders.meta.total} href="/seller/orders" />
    </dl>
  );
}

function Figure({ term, value, href }: { term: string; value: number; href?: string }) {
  return (
    <div className="bg-card border-border rounded-lg border p-4">
      <dt className="text-muted-foreground text-xs font-semibold tracking-wide uppercase">
        {term}
      </dt>
      <dd className="mt-1 text-2xl font-bold tabular-nums">{value}</dd>
      {href ? (
        <dd className="mt-1 text-sm">
          <Link href={href} className="text-primary font-medium hover:underline">
            See the {term.toLowerCase()}
          </Link>
        </dd>
      ) : null}
    </div>
  );
}

function Detail({ term, children }: { term: string; children: ReactNode }) {
  return (
    <div className="grid gap-1 px-4 py-3 sm:grid-cols-[10rem_minmax(0,1fr)] sm:gap-4">
      <dt className="text-muted-foreground text-sm">{term}</dt>
      <dd className="text-sm break-words">{children}</dd>
    </div>
  );
}
