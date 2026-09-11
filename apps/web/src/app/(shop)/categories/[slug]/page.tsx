import type { Metadata } from "next";
import Link from "next/link";
import { notFound, unstable_rethrow } from "next/navigation";
import { cache } from "react";

import { ProductGrid } from "@/components/catalogue/product-grid";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Pagination } from "@/components/ui/pagination";
import { PillLink } from "@/components/ui/pill-link";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { Category, CategoryListings, CategoryTree, Resource } from "@/lib/api/types";
import { categoryHref } from "@/lib/catalogue/category-href";
import { listingCount } from "@/lib/catalogue/listing-count";

/**
 * One category, as a place.
 *
 * `/search?category=audio` returns the same listings, and this is still its own
 * page, because the two are different things. A search result is one of an
 * unbounded number, changes by the hour and is `noindex` (ADR 0026). A category
 * is durable: it has an address worth sharing and a search engine keeping, a
 * breadcrumb, and the subcategories beside it. The API gives it its own
 * endpoint for the same reason (ADR 0017).
 *
 * **The API decides whether a category exists.** An unknown slug is a 404 from
 * `GET /categories/{slug}/products`, and becomes this application's not-found
 * page - not a lookup in the tree here, which would be a second answer to the
 * same question.
 */
type Props = PageProps<"/categories/[slug]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const place = locate(await readTree(), slug);

  return { title: place?.category.name ?? "Browse" };
}

export default async function CategoryPage({ params, searchParams }: Props) {
  const { slug } = await params;
  const page = single((await searchParams).page);

  const [listings, tree] = await Promise.all([readListings(slug, page), readTree()]);
  const place = locate(tree, slug);

  // The API has already said this category exists. If the tree failed to load,
  // the page still renders its listings under a plainer heading rather than
  // pretending the category is missing.
  const name = place?.category.name ?? "Listings";
  const { data, meta } = listings;

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-8 sm:py-10">
      <nav aria-label="Breadcrumb">
        <ol className="text-muted-foreground flex flex-wrap items-center gap-1.5 text-sm">
          <li>
            <Link href="/search" className="hover:text-foreground">
              Everything
            </Link>
          </li>
          {place?.parent ? (
            <li className="flex items-center gap-1.5">
              <span aria-hidden="true">/</span>
              <Link href={categoryHref(place.parent.slug)} className="hover:text-foreground">
                {place.parent.name}
              </Link>
            </li>
          ) : null}
          <li className="flex items-center gap-1.5">
            <span aria-hidden="true">/</span>
            <span aria-current="page" className="text-foreground font-medium">
              {name}
            </span>
          </li>
        </ol>
      </nav>

      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">{name}</h1>
        <p className="text-muted-foreground text-sm">{listingCount(meta.total)}</p>
      </header>

      {place ? <Subcategories place={place} /> : null}

      {/*
       * Hands over to search with the category already chosen. A plain GET
       * form, so it works before any JavaScript has loaded, and `q` comes first
       * so the address is the one `searchHref` would build for the same search.
       * No `minLength`: a one-letter term is refused by the API, on the search
       * page, in its own words (ADR 0026).
       */}
      <form action="/search" method="get" role="search" className="flex max-w-md gap-2">
        <Input
          type="search"
          name="q"
          aria-label={`Search in ${name}`}
          placeholder={`Search in ${name}`}
          className="h-9"
        />
        <input type="hidden" name="category" value={slug} />
        <Button type="submit" variant="secondary" size="sm">
          Search
        </Button>
      </form>

      {meta.total === 0 ? (
        <div className="bg-card border-border space-y-3 rounded-lg border p-6">
          <p className="font-semibold">Nothing is listed in {name} yet.</p>
          <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
            {place?.parent ? (
              <Link
                href={categoryHref(place.parent.slug)}
                className="text-primary font-medium hover:underline"
              >
                See everything in {place.parent.name}
              </Link>
            ) : null}
            <Link href="/search" className="text-primary font-medium hover:underline">
              Browse everything
            </Link>
          </p>
        </div>
      ) : data.length === 0 ? (
        // Past the end: an ordinary race rather than an error (ADR 0022).
        <p className="bg-card border-border text-muted-foreground rounded-lg border p-6 text-sm">
          There is nothing on page {meta.current_page}.{" "}
          <Link href={categoryHref(slug)} className="text-primary font-medium hover:underline">
            Back to the first page
          </Link>
        </p>
      ) : (
        // Under an h2, because every card is an h3 (ProductGrid says why).
        <section aria-labelledby="listings-heading">
          <h2 id="listings-heading" className="sr-only">
            Listings
          </h2>
          <ProductGrid products={data} />
        </section>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(target) => categoryHref(slug, target)}
        className="pt-4"
      />
    </div>
  );
}

type Place = { category: Category; parent: Category | null };

/**
 * A top-level category shows its children; a subcategory shows its siblings,
 * itself among them, so moving sideways from Headphones to Turntables is one
 * click. Going up is the breadcrumb's job.
 */
function Subcategories({ place }: { place: Place }) {
  const row = place.parent ? place.parent.children : place.category.children;

  if (row.length === 0) {
    return null;
  }

  return (
    <nav aria-label="Subcategories">
      <ul className="flex flex-wrap gap-2">
        {row.map((candidate) => (
          <li key={candidate.slug}>
            <PillLink
              href={categoryHref(candidate.slug)}
              active={candidate.slug === place.category.slug}
            >
              {candidate.name}
            </PillLink>
          </li>
        ))}
      </ul>
    </nav>
  );
}

async function readListings(slug: string, page: string | null): Promise<CategoryListings> {
  const query = page ? `?page=${encodeURIComponent(page)}` : "";

  try {
    return await serverFetch<CategoryListings>(
      `/categories/${encodeURIComponent(slug)}/products${query}`,
    );
  } catch (error) {
    unstable_rethrow(error);

    if (error instanceof ApiError && error.status === 404) {
      notFound();
    }

    throw error;
  }
}

/**
 * The category tree, asked for once per request.
 *
 * Both `generateMetadata` and the page need it. `serverFetch` opts out of Next's
 * fetch cache on purpose - it carries a session - so without React's `cache`
 * this would be two requests to the API for one page.
 */
const readTree = cache(async (): Promise<CategoryTree> => {
  try {
    return (await serverFetch<Resource<CategoryTree>>("/categories")).data;
  } catch (error) {
    unstable_rethrow(error);
    console.error("The category page could not load the category tree.", error);

    return [];
  }
});

function locate(tree: CategoryTree, slug: string): Place | null {
  for (const root of tree) {
    if (root.slug === slug) {
      return { category: root, parent: null };
    }

    const child = root.children.find((candidate) => candidate.slug === slug);

    if (child) {
      return { category: child, parent: root };
    }
  }

  return null;
}

function single(value: string | string[] | undefined): string | null {
  return typeof value === "string" && value.trim() !== "" ? value.trim() : null;
}
