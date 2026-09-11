import type { Metadata } from "next";
import Link from "next/link";

import { CheckoutForm } from "@/components/checkout/checkout-form";
import { OrderSummary } from "@/components/checkout/order-summary";
import { Alert } from "@/components/ui/alert";
import { buttonStyles } from "@/components/ui/button";
import { serverFetch } from "@/lib/api/server";
import type { AddressBook, Cart, Resource } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = {
  title: "Checkout",
  robots: { index: false, follow: false },
};

/**
 * Turning a basket into orders.
 *
 * **The cart says whether it can be checked out, and this page draws the
 * answer.** `checkout_blocker` is the API's: nothing in the basket, an
 * unconfirmed email address, or a line that can no longer be bought. The page
 * does not look at `email_verified_at` and decide for itself that checkout needs
 * it - that rule is the `verified` middleware on the checkout route, and a copy
 * here is the one that would drift (ADR 0030).
 *
 * Nothing without a session, so `requireUser` sends a signed-out visitor to
 * sign in and back (ADR 0029).
 */
export default async function CheckoutPage() {
  await requireUser("/checkout");

  const [cart, addresses] = await Promise.all([
    serverFetch<Resource<Cart>>("/cart").then((response) => response.data),
    serverFetch<Resource<AddressBook>>("/addresses").then((response) => response.data),
  ]);

  return (
    <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8 sm:py-10">
      <h1 className="text-2xl font-bold tracking-tight">Checkout</h1>

      <Blocked cart={cart} />

      {cart.checkout_blocker === null ? (
        <div className="grid gap-8 lg:grid-cols-[1fr_24rem] lg:gap-12">
          <CheckoutForm addresses={addresses} />
          <OrderSummary cart={cart} />
        </div>
      ) : null}
    </div>
  );
}

/**
 * The next step for whatever the cart says is in the way. Exhaustive over the
 * API's cases, so a new blocker fails the type check rather than rendering a
 * checkout that the API will refuse.
 */
function Blocked({ cart }: { cart: Cart }) {
  switch (cart.checkout_blocker) {
    case null:
      return null;

    case "empty":
      return (
        <div className="bg-card border-border space-y-3 rounded-lg border p-6">
          <p className="font-semibold">There is nothing to check out.</p>
          <p className="text-muted-foreground text-sm">Your cart is empty.</p>
          <Link href="/search" className={buttonStyles({ variant: "secondary", size: "sm" })}>
            Browse everything
          </Link>
        </div>
      );

    case "unverified_email":
      return (
        <div className="space-y-3">
          <Alert tone="danger">
            Checking out needs a confirmed email address, because your receipt goes to it.
          </Alert>
          <Link href="/verify-email/sent" className={buttonStyles({ size: "sm" })}>
            Confirm your email address
          </Link>
        </div>
      );

    case "unavailable_items": {
      const blocked = cart.shops.flatMap((shop) =>
        shop.items.filter((item) => item.availability !== "available"),
      );

      return (
        <div className="space-y-3">
          <Alert tone="danger">
            <p>Some of your basket can no longer be bought as it is:</p>
            <ul className="mt-2 list-disc space-y-1 pl-5">
              {blocked.map((item) => (
                <li key={item.id}>
                  {item.product_name}, {item.variant_name}
                </li>
              ))}
            </ul>
          </Alert>
          <Link href="/cart" className={buttonStyles({ size: "sm" })}>
            Back to your cart
          </Link>
        </div>
      );
    }

    default: {
      const unhandled: never = cart.checkout_blocker;

      return unhandled;
    }
  }
}
