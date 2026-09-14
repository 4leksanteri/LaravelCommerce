import type { Metadata } from "next";
import Link from "next/link";
import { notFound, unstable_rethrow } from "next/navigation";
import { cache } from "react";

import { AddToCart } from "@/components/cart/add-to-cart";
import { ProductGallery } from "@/components/catalogue/product-gallery";
import { RatingStars } from "@/components/catalogue/rating-stars";
import { ReportControl } from "@/components/catalogue/report-control";
import { ReviewForm } from "@/components/catalogue/review-form";
import { ReviewList } from "@/components/catalogue/review-list";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { PublicProduct, Resource, Review, ReviewPage } from "@/lib/api/types";
import { currentUser } from "@/lib/auth/session";
import { categoryHref } from "@/lib/catalogue/category-href";
import { formatMoney } from "@/lib/money";

/**
 * One listing: what it is, who sells it, what it costs, and the way to buy it.
 *
 * **Public, and only the action needs a session.** A signed-out shopper can
 * read everything here; `currentUser()` decides whether the purchase control is
 * a button or a link to sign in and come back. That is the answer to the
 * question ADR 0023 left open, and it is deliberately narrow: this page is not
 * guarded, its one write is.
 *
 * **The API decides whether the listing exists.** A draft, a deleted listing
 * and an unapproved shop's listing all answer 404 from the storefront endpoint,
 * so all three are this application's not-found page - and none of them says
 * which it was (ADR 0007).
 *
 * The shop's name is text rather than a link. There is no shop page, and the
 * design export's direction may never need one (ADR 0024); a link that always
 * lands on not-found would be the one dead link on a page people act on.
 */
type Props = PageProps<"/shops/[shopSlug]/products/[productSlug]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { shopSlug, productSlug } = await params;
  const product = await readProduct(shopSlug, productSlug);

  return { title: product.name, description: summary(product.description) };
}

export default async function ProductPage({ params }: Props) {
  const { shopSlug, productSlug } = await params;
  const [product, user, reviews] = await Promise.all([
    readProduct(shopSlug, productSlug),
    currentUser(),
    readReviews(shopSlug, productSlug),
  ]);

  // Built from the API's slugs rather than the URL's, so the address a person
  // is sent back to after signing in is the listing's own.
  const here = `/shops/${product.shop_slug}/products/${product.slug}`;

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-8 sm:py-10">
      <nav aria-label="Breadcrumb">
        <ol className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-sm">
          <li>
            <Link href="/search" className="hover:text-foreground">
              Everything
            </Link>
          </li>
          {product.category ? (
            <li className="flex items-center gap-1.5">
              <span aria-hidden="true">/</span>
              <Link href={categoryHref(product.category.slug)} className="hover:text-foreground">
                {product.category.name}
              </Link>
            </li>
          ) : null}
        </ol>
      </nav>

      <div className="grid gap-8 lg:grid-cols-2 lg:gap-12">
        <ProductGallery images={product.images} name={product.name} />

        <div className="space-y-6">
          <header className="space-y-1.5">
            {/*
             * The shop's name is a link now that there is a shop to link to
             * (ADR 0053). ADR 0028 made it text deliberately, because a link
             * that always led to not-found would have been the one dead end on
             * a page people act on - that reason has gone rather than been
             * overruled.
             */}
            <p className="text-muted-foreground text-sm">
              Sold by{" "}
              <Link
                href={`/shops/${product.shop_slug}`}
                className="text-foreground font-medium hover:underline"
              >
                {product.shop_name}
              </Link>
            </p>
            <h1 className="text-2xl font-bold tracking-tight sm:text-3xl">{product.name}</h1>
          </header>

          {/*
           * Your own shop's listing (ADR 0056).
           *
           * Checked before stock, because "sold out" would be the wrong answer
           * to give somebody about their own listing - one of those is fixable
           * by waiting and the other never becomes true. The same order
           * `CartItem::availability()` uses.
           *
           * **The rule still lives at checkout**, where the money is (ADR
           * 0010). This is only so the page does not offer a button that leads
           * to a cart line which can never be bought.
           */}
          {product.is_your_own ? (
            <YourOwnListing product={product} />
          ) : product.in_stock ? (
            <AddToCart
              variants={product.variants}
              currency={product.currency}
              signInHref={user ? null : `/login?next=${encodeURIComponent(here)}`}
            />
          ) : (
            <SoldOut product={product} />
          )}

          <section aria-labelledby="description-heading" className="space-y-2">
            <h2 id="description-heading" className="text-sm font-semibold">
              About this listing
            </h2>
            <p className="text-muted-foreground text-sm leading-relaxed whitespace-pre-line">
              {product.description || "The seller has not described this yet."}
            </p>
          </section>

          {/*
           * Reporting the listing (ADR 0054).
           *
           * `can_report` is the API's answer - false for a guest and false on
           * your own shop - and a signed-out shopper is offered the way to sign
           * in instead, which is the one action on this public page that needs
           * a session (ADR 0028). The shop's owner is offered neither.
           *
           * Quiet, and last. It is not what anybody came to this page to do.
           */}
          {product.can_report || !user ? (
            <div className="border-t pt-4">
              <ReportControl
                path={`/shops/${encodeURIComponent(product.shop_slug)}/products/${encodeURIComponent(product.slug)}/reports`}
                subject="this listing"
                signInHref={user ? null : `/login?next=${encodeURIComponent(here)}`}
              />
            </div>
          ) : null}
        </div>
      </div>

      {/*
       * What people who bought it thought (ADR 0047).
       *
       * Whether the form appears at all is the API's answer: `can_review` is
       * true only for somebody with a completed order who has not yet had their
       * say, and `your_review` comes back when they have. Nothing here works
       * out either - the browser cannot see an order history.
       */}
      <section aria-labelledby="reviews-heading" className="space-y-4 border-t pt-8">
        <div className="flex flex-wrap items-baseline justify-between gap-3">
          <h2 id="reviews-heading" className="text-lg font-semibold">
            Reviews
          </h2>
          <RatingStars rating={product.rating} count={product.review_count} />
        </div>

        {product.can_review || product.your_review ? (
          <div className="bg-card border-border space-y-3 rounded-lg border p-4">
            <h3 className="text-sm font-semibold">
              {product.your_review ? "Your review" : "You bought this. What did you think?"}
            </h3>
            <ReviewForm
              shopSlug={product.shop_slug}
              productSlug={product.slug}
              existing={product.your_review}
            />
          </div>
        ) : null}

        <ReviewList reviews={reviews} shopSlug={product.shop_slug} productSlug={product.slug} />
      </section>
    </div>
  );
}

/**
 * The viewer's own shop sells this (ADR 0056).
 *
 * It still shows the price, for the reason `SoldOut` does: a seller looking at
 * their own listing as a shopper sees it wants to see what a shopper sees.
 * What it does not do is offer a button, because nothing could come of one.
 */
function YourOwnListing({ product }: { product: PublicProduct }) {
  return (
    <div className="space-y-3">
      <Price product={product} />
      <p className="bg-muted border-border rounded-md border px-3 py-2.5 text-sm font-medium">
        This is your shop. You cannot buy your own listing.
      </p>
    </div>
  );
}

/**
 * Nothing left in any option. Still says what it cost - availability is its
 * own answer, and a price is worth knowing before asking the shop about it
 * (ADR 0024).
 */
function SoldOut({ product }: { product: PublicProduct }) {
  return (
    <div className="space-y-3">
      <Price product={product} />
      <p className="bg-muted border-border rounded-md border px-3 py-2.5 text-sm font-medium">
        Sold out
      </p>
    </div>
  );
}

/**
 * What this costs, as the two states that cannot be bought show it.
 *
 * Extracted because both of them say it and say it identically - a listing
 * somebody cannot buy still has a price worth knowing. `from` appears only when
 * the options differ, and the figure is the API's: `price_from_minor` is a rule
 * about which price to advertise, and this formats it rather than deriving it
 * (ADR 0024).
 */
function Price({ product }: { product: PublicProduct }) {
  const { price_from_minor: from, price_to_minor: to, currency } = product;

  if (from === null || to === null) {
    return null;
  }

  return (
    <p className="text-muted-foreground text-3xl font-bold tracking-tight tabular-nums">
      {from === to ? null : (
        <>
          <span className="text-base font-medium">from</span>{" "}
        </>
      )}
      {formatMoney(from, currency)}
    </p>
  );
}

/**
 * The listing, asked for once per request. Both `generateMetadata` and the page
 * need it, and `serverFetch` deliberately skips Next's fetch cache because it
 * carries a session - so without React's `cache` this would be two requests.
 */
const readProduct = cache(async (shopSlug: string, productSlug: string): Promise<PublicProduct> => {
  const path = `/shops/${encodeURIComponent(shopSlug)}/products/${encodeURIComponent(productSlug)}`;

  try {
    return (await serverFetch<Resource<PublicProduct>>(path)).data;
  } catch (error) {
    unstable_rethrow(error);

    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }
});

/**
 * The listing's reviews, newest first.
 *
 * **The first page and no pagination.** A listing with more than twenty reviews
 * is not a problem this marketplace has yet; the endpoint pages already, so the
 * day it does, this reads a `?page=` rather than gaining an endpoint.
 *
 * A 404 here is a listing that is not on sale, which `readProduct` has already
 * turned into the not-found page. Returning nothing rather than throwing keeps
 * that the one place it is decided.
 */
const readReviews = cache(async (shopSlug: string, productSlug: string): Promise<Review[]> => {
  const path = `/shops/${encodeURIComponent(shopSlug)}/products/${encodeURIComponent(productSlug)}/reviews`;

  try {
    return (await serverFetch<ReviewPage>(path)).data;
  } catch (error) {
    unstable_rethrow(error);

    if (error instanceof ApiError && error.status === 404) {
      return [];
    }

    throw error;
  }
});

/** The first sentence or so, for a search result's snippet. */
function summary(description: string | null | undefined): string | undefined {
  const text = (description ?? "").replace(/\s+/g, " ").trim();

  if (text === "") {
    return undefined;
  }

  return text.length <= 155 ? text : `${text.slice(0, 152).replace(/\s+\S*$/, "")}...`;
}
