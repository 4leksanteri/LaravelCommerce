import Link from "next/link";
import { unstable_rethrow } from "next/navigation";
import { Suspense } from "react";

import { SearchField } from "@/components/shell/search-field";
import { SearchInput } from "@/components/shell/search-input";
import { SignOutButton } from "@/components/shell/sign-out-button";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { Cart, CategoryTree, Resource } from "@/lib/api/types";
import { currentUser } from "@/lib/auth/session";

/**
 * The header, on every page except the auth screens.
 *
 * Rendered on the server, because everything in it is a question only the API
 * can answer: who is signed in, whether they run a shop, what is in their
 * basket. None of that is mirrored in the browser (ADR 0023).
 *
 * **Three things the design export shows here are missing on purpose.** It has
 * "Saved" and "Messages" beside the cart, and a search box promising "240,000
 * items from 6,100 shops". There is no favourites domain and no messages
 * domain, and those numbers were invented for a mockup. `apps/web/CLAUDE.md`
 * says to build the screens the API feeds and not to stub the rest into looking
 * real, and a header is the worst place to break that: it is on every page, so
 * a dead link there is dead everywhere.
 */
export async function SiteHeader() {
  const [user, categories] = await Promise.all([currentUser(), readCategories()]);
  const cart = user ? await readCart() : null;

  return (
    <header className="bg-card border-border sticky top-0 z-40 border-b">
      <div className="mx-auto flex w-full max-w-7xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3">
        <Link
          href="/"
          className="focus-visible:ring-ring rounded-sm text-base font-semibold tracking-tight outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
        >
          Laravel Commerce
        </Link>

        {/*
         * A plain GET form: the browser serialises `q` into the query string
         * and Next routes it, so searching works before any JavaScript has
         * loaded - which for the one control every visitor uses is worth more
         * than an onSubmit handler.
         *
         * The box inside is a client component for one reason only: to show
         * the term being searched for. This header sits in a layout, and a
         * layout is not given the query string. The Suspense boundary is what
         * the Next docs ask for around `useSearchParams`, and its fallback is
         * the same box, empty, which still submits.
         */}
        <form
          action="/search"
          method="get"
          className="order-3 flex w-full gap-2 sm:order-none sm:w-auto sm:flex-1"
        >
          <Suspense fallback={<SearchInput />}>
            <SearchField />
          </Suspense>
          <button
            type="submit"
            className="bg-secondary text-secondary-foreground hover:bg-secondary/80 focus-visible:ring-ring h-9 shrink-0 rounded-md px-4 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
          >
            Search
          </button>
        </form>

        <nav className="ml-auto flex items-center gap-4 text-sm" aria-label="Account">
          <Link
            href={user?.has_shop ? "/seller" : "/sell"}
            className="hover:text-primary font-medium"
          >
            {user?.has_shop ? "Your shop" : "Open a shop"}
          </Link>

          {/*
           * Named in words when there is a count. The badge is a number beside
           * a word, and "Cart1" is what a screen reader makes of the two - the
           * same trap as "fromDKK" on the product card (ADR 0024).
           */}
          <Link
            href="/cart"
            aria-label={
              cart && cart.item_count > 0
                ? `Cart, ${cart.item_count} ${cart.item_count === 1 ? "item" : "items"}`
                : undefined
            }
            className="hover:text-primary font-medium"
          >
            Cart
            {cart && cart.item_count > 0 ? (
              <span
                aria-hidden="true"
                className="bg-primary text-primary-foreground ml-1.5 rounded-full px-1.5 py-0.5 text-xs font-semibold tabular-nums"
              >
                {cart.item_count}
              </span>
            ) : null}
          </Link>

          {/*
           * Staff have one page, and this is the way to it (ADR 0037). Drawn
           * from the API's answer rather than from a role this application
           * would have to interpret.
           */}
          {user?.can_review_sellers ? (
            <Link href="/admin/shops" className="hover:text-primary font-medium">
              Review shops
            </Link>
          ) : null}

          {user ? (
            <>
              <Link href="/account" className="hover:text-primary font-medium">
                Your account
              </Link>
              <SignOutButton />
            </>
          ) : (
            <Link href="/login" className="hover:text-primary font-medium">
              Sign in
            </Link>
          )}
        </nav>
      </div>

      {categories.length > 0 ? (
        <nav aria-label="Categories" className="border-border border-t">
          <ul className="mx-auto flex w-full max-w-7xl gap-5 overflow-x-auto px-4 py-2 text-sm whitespace-nowrap">
            {categories.map((category) => (
              <li key={category.slug}>
                <Link
                  href={`/categories/${category.slug}`}
                  className="text-muted-foreground hover:text-foreground"
                >
                  {category.name}
                </Link>
              </li>
            ))}
          </ul>
        </nav>
      ) : null}

      {/*
       * The one claim this marketplace makes, and it is load-bearing rather
       * than marketing: an order releases when the buyer confirms, or fourteen
       * days after dispatch (ADR 0014). The export's design notes ask for the
       * escrow position to be legible on every screen, and this is the cheapest
       * honest way to do it.
       */}
      <p className="bg-accent text-accent-foreground border-border border-t px-4 py-1.5 text-center text-xs font-medium">
        Every payment is held until you confirm the parcel arrived.
      </p>
    </header>
  );
}

async function readCategories(): Promise<CategoryTree> {
  try {
    const response = await serverFetch<Resource<CategoryTree>>("/categories");

    return response.data;
  } catch (error) {
    unstable_rethrow(error);

    // A header that cannot list categories is worth less than a page that does
    // not render at all, so this degrades rather than throws.
    console.error("The category navigation could not be loaded.", error);

    return [];
  }
}

async function readCart(): Promise<Cart | null> {
  try {
    const response = await serverFetch<Resource<Cart>>("/cart");

    return response.data;
  } catch (error) {
    unstable_rethrow(error);

    if (error instanceof ApiError && error.isUnauthenticated) {
      return null;
    }

    console.error("The cart count could not be loaded.", error);

    return null;
  }
}
