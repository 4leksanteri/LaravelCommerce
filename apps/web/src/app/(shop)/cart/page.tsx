import type { Metadata } from "next";
import Link from "next/link";

import { CartLine } from "@/components/cart/cart-line";
import { Alert } from "@/components/ui/alert";
import { Button, buttonStyles } from "@/components/ui/button";
import { serverFetch } from "@/lib/api/server";
import type { Cart, CartShop, Resource } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { formatMoney } from "@/lib/money";

export const metadata: Metadata = {
  title: "Your cart",
  // Somebody's own basket. Nothing here is for a search engine.
  robots: { index: false, follow: false },
};

/**
 * The basket, grouped by shop.
 *
 * **Nothing without a session, so it redirects rather than drawing a shell.** A
 * product page is readable signed out and only its button needs a session
 * (ADR 0028); this page has nothing to show anybody but its owner, so
 * `requireUser` sends a signed-out visitor to sign in and back.
 *
 * **One group per shop, each with its own subtotal, and no total across them.**
 * Every shop prices in its own currency and each group becomes its own order and
 * its own payment (ADR 0004, ADR 0011). A figure adding euros to pounds is not a
 * number, so the page says why there is not one rather than leaving a reader to
 * wonder where it went.
 *
 * **Drawn entirely from the API's answers.** Line totals, subtotals and whether
 * each line can still be bought all arrive computed. After any change the page
 * asks again, and nothing is worked out here in the meantime.
 */
export default async function CartPage() {
  await requireUser("/cart");

  const cart = (await serverFetch<Resource<Cart>>("/cart")).data;

  return (
    <div className="mx-auto w-full max-w-3xl space-y-6 px-4 py-8 sm:py-10">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Your cart</h1>
        {cart.shops.length > 0 ? (
          <p className="text-muted-foreground text-sm">
            {cart.item_count === 1 ? "1 item" : `${cart.item_count} items`}
          </p>
        ) : null}
      </header>

      {cart.shops.length === 0 ? (
        <div className="bg-card border-border space-y-3 rounded-lg border p-6">
          <p className="font-semibold">Your cart is empty.</p>
          <Link href="/search" className="text-primary text-sm font-medium hover:underline">
            Browse everything
          </Link>
        </div>
      ) : (
        <>
          {cart.has_unavailable_items ? (
            <Alert tone="danger">
              Some of this can no longer be bought as it is. Change or remove the marked lines
              before checking out.
            </Alert>
          ) : null}

          {cart.shops.map((shop) => (
            <ShopGroup key={shop.shop_slug} shop={shop} />
          ))}

          <p className="text-muted-foreground text-sm leading-relaxed">
            Each shop is a separate order, paid in its own currency, so there is no total across
            them. Your payment to each is held until you confirm its parcel arrived.
          </p>

          {/*
           * Drawn from `has_unavailable_items`, which the API describes as the
           * answer a checkout button needs. Disabled rather than hidden, so the
           * reason above has something to be the reason for.
           */}
          {cart.has_unavailable_items ? (
            <Button type="button" size="block" disabled>
              Continue to checkout
            </Button>
          ) : (
            <Link href="/checkout" className={buttonStyles({ size: "block" })}>
              Continue to checkout
            </Link>
          )}
        </>
      )}
    </div>
  );
}

function ShopGroup({ shop }: { shop: CartShop }) {
  const heading = `cart-shop-${shop.shop_slug}`;

  return (
    <section aria-labelledby={heading} className="bg-card border-border rounded-lg border">
      <header className="border-border flex items-baseline justify-between gap-4 border-b px-4 py-3">
        <h2 id={heading} className="font-semibold">
          {shop.shop_name}
        </h2>
        <span className="text-muted-foreground text-xs">Paid in {shop.currency}</span>
      </header>

      <ul className="divide-border divide-y px-4">
        {shop.items.map((item) => (
          <CartLine key={item.id} item={item} shopSlug={shop.shop_slug} currency={shop.currency} />
        ))}
      </ul>

      <footer className="border-border flex items-baseline justify-between gap-4 border-t px-4 py-3 text-sm">
        <span className="text-muted-foreground">Subtotal</span>
        <span className="font-semibold tabular-nums">
          {formatMoney(shop.subtotal_minor, shop.currency)}
        </span>
      </footer>
    </section>
  );
}
