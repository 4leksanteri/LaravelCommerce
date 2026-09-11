import type { Metadata } from "next";
import Link from "next/link";
import { unstable_rethrow } from "next/navigation";

import { ProductGrid } from "@/components/catalogue/product-grid";
import { Alert } from "@/components/ui/alert";
import { Pagination } from "@/components/ui/pagination";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type {
  Category,
  CategoryTree,
  Resource,
  SearchResults,
  ValidationErrors,
} from "@/lib/api/types";
import { searchHref } from "@/lib/catalogue/search-href";
import { cn } from "@/lib/utils";

/**
 * Searching the whole marketplace, and browsing it when nothing is typed.
 *
 * `GET /search` with no term is everything, newest first (ADR 0020), so this
 * page is also "Start browsing" and "See everything". The API decides the
 * ordering - relevance when there is a term, newest when not - and this page
 * does not say which, because saying so would be a copy of that rule.
 *
 * **A refusal is the API's, rendered as it said it.** A one-letter search and
 * an unknown category are both 422s with a message. There is deliberately no
 * `minLength` on the search box: the rule is `min:2` in `SearchRequest`, and a
 * second copy in the browser is the one that drifts (root CLAUDE.md section 4).
 *
 * Paged by `meta` rather than by the URL. `?page=abc` is page 1 as far as
 * Laravel's paginator is concerned, so the pager draws what the API says the
 * current page is, not what somebody typed.
 */
type Props = PageProps<"/search">;

export async function generateMetadata({ searchParams }: Props): Promise<Metadata> {
  const q = single((await searchParams).q);

  return {
    title: q ? `Search: ${q}` : "Everything for sale",
    // A results page is one of an unbounded number, and changes by the hour.
    // There is nothing on it worth a search engine keeping.
    robots: { index: false, follow: true },
  };
}

export default async function SearchPage({ searchParams }: Props) {
  const params = await searchParams;
  const q = single(params.q);
  const category = single(params.category);

  const query = new URLSearchParams();
  if (q) query.set("q", q);
  if (category) query.set("category", category);
  if (single(params.page)) query.set("page", single(params.page) as string);

  const [outcome, categories] = await Promise.all([runSearch(query), readCategories()]);
  const selected = category ? findCategory(categories, category) : null;

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-4 py-8 sm:py-10">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">
          {q ? (
            <>Results for &ldquo;{q}&rdquo;</>
          ) : selected ? (
            selected.name
          ) : (
            "Everything for sale"
          )}
        </h1>

        {outcome.kind === "results" ? (
          <p className="text-muted-foreground text-sm">
            {countOf(outcome.results.meta.total)}
            {q && selected ? ` in ${selected.name}` : null}
          </p>
        ) : null}
      </header>

      <CategoryFilter categories={categories} q={q} selected={selected} />

      {outcome.kind === "refused" ? (
        <Refusal errors={outcome.errors} q={q} />
      ) : (
        <Results results={outcome.results} q={q} category={category} selected={selected} />
      )}
    </div>
  );
}

function Results({
  results,
  q,
  category,
  selected,
}: {
  results: SearchResults;
  q: string | null;
  category: string | null;
  selected: Category | null;
}) {
  const { data, meta } = results;

  if (meta.total === 0) {
    return (
      <div className="bg-card border-border space-y-3 rounded-lg border p-6">
        <p className="font-semibold">
          {q ? <>Nothing matches &ldquo;{q}&rdquo;.</> : "Nothing is listed here yet."}
        </p>
        {q ? (
          <p className="text-muted-foreground text-sm">
            Check the spelling, or try a shorter or more general word.
          </p>
        ) : null}
        <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
          {q && selected ? (
            <Link href={searchHref({ q })} className="text-primary font-medium hover:underline">
              Search all categories instead
            </Link>
          ) : null}
          <Link href="/search" className="text-primary font-medium hover:underline">
            Browse everything
          </Link>
        </p>
      </div>
    );
  }

  return (
    <>
      {data.length > 0 ? (
        <section aria-labelledby="results-heading">
          {/*
           * Visually hidden, and doing two jobs. Cards title themselves with an
           * h3, which on this page sat straight under the h1 until axe's
           * heading-order check caught the skipped level. And somebody moving
           * through the page by headings can now jump past the filters
           * straight to what was found.
           */}
          <h2 id="results-heading" className="sr-only">
            Listings
          </h2>
          <ProductGrid products={data} />
        </section>
      ) : (
        // Past the end: `?page=40` of a shorter set. An ordinary race rather
        // than an error, usually something deleted mid-browse (ADR 0022).
        <p className="bg-card border-border text-muted-foreground rounded-lg border p-6 text-sm">
          There is nothing on page {meta.current_page}.{" "}
          <Link
            href={searchHref({ q, category })}
            className="text-primary font-medium hover:underline"
          >
            Back to the first page
          </Link>
        </p>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(page) => searchHref({ q, category, page })}
        className="pt-4"
      />
    </>
  );
}

/**
 * Every message the API sent, as it sent it. The query stays in the header's
 * box, so the person can correct it where they typed it.
 */
function Refusal({ errors, q }: { errors: ValidationErrors; q: string | null }) {
  const messages = Object.values(errors).flat();

  return (
    <div className="space-y-3">
      <Alert tone="danger">
        <ul className="space-y-1">
          {messages.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      </Alert>

      <p className="flex flex-wrap gap-x-4 gap-y-1 text-sm">
        {errors.category && q ? (
          <Link href={searchHref({ q })} className="text-primary font-medium hover:underline">
            Search all categories instead
          </Link>
        ) : null}
        <Link href="/search" className="text-primary font-medium hover:underline">
          Browse everything
        </Link>
      </p>
    </div>
  );
}

/**
 * Narrowing by category, keeping the query.
 *
 * The top level always; a category's own subcategories once it, or one of
 * them, is chosen. The API includes everything underneath a category when
 * filtering by it (ADR 0017), so choosing "Audio" already finds the
 * turntables - the second row is for narrowing further, not for finding them.
 */
function CategoryFilter({
  categories,
  q,
  selected,
}: {
  categories: CategoryTree;
  q: string | null;
  selected: Category | null;
}) {
  if (categories.length === 0) {
    return null;
  }

  const root = selected
    ? (categories.find(
        (candidate) => candidate.slug === selected.slug || candidate.slug === selected.parent_slug,
      ) ?? null)
    : null;

  return (
    <nav aria-label="Filter by category" className="space-y-2">
      <ul className="flex flex-wrap gap-2">
        <FilterLink href={searchHref({ q })} active={!selected}>
          All categories
        </FilterLink>
        {categories.map((candidate) => (
          <FilterLink
            key={candidate.slug}
            href={searchHref({ q, category: candidate.slug })}
            active={root?.slug === candidate.slug}
          >
            {candidate.name}
          </FilterLink>
        ))}
      </ul>

      {root && root.children.length > 0 ? (
        <ul className="flex flex-wrap gap-2 pl-1">
          {root.children.map((child) => (
            <FilterLink
              key={child.slug}
              href={searchHref({ q, category: child.slug })}
              active={selected?.slug === child.slug}
              subtle
            >
              {child.name}
            </FilterLink>
          ))}
        </ul>
      ) : null}
    </nav>
  );
}

function FilterLink({
  href,
  active,
  subtle = false,
  children,
}: {
  href: string;
  active: boolean;
  subtle?: boolean;
  children: React.ReactNode;
}) {
  return (
    <li>
      <Link
        href={href}
        // `true` rather than `page`: this marks the chosen item in a set of
        // filters, not the page the person is on.
        aria-current={active ? "true" : undefined}
        className={cn(
          "focus-visible:ring-ring inline-flex h-8 items-center rounded-full border px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-offset-2",
          active
            ? "border-primary bg-primary text-primary-foreground"
            : "border-border bg-card hover:bg-accent hover:text-accent-foreground",
          subtle && !active && "text-muted-foreground",
        )}
      >
        {children}
      </Link>
    </li>
  );
}

type Outcome =
  { kind: "results"; results: SearchResults } | { kind: "refused"; errors: ValidationErrors };

async function runSearch(query: URLSearchParams): Promise<Outcome> {
  const suffix = query.toString();

  try {
    return {
      kind: "results",
      results: await serverFetch<SearchResults>(`/search${suffix ? `?${suffix}` : ""}`),
    };
  } catch (error) {
    unstable_rethrow(error);

    const validation = error instanceof ApiError ? error.validationErrors : null;

    if (validation) {
      return { kind: "refused", errors: validation };
    }

    // Anything else is the API failing, and "no results" would be a lie about
    // the catalogue. It is left to throw.
    throw error;
  }
}

async function readCategories(): Promise<CategoryTree> {
  try {
    return (await serverFetch<Resource<CategoryTree>>("/categories")).data;
  } catch (error) {
    unstable_rethrow(error);
    console.error("The search page could not load categories.", error);

    return [];
  }
}

function findCategory(tree: CategoryTree, slug: string): Category | null {
  for (const root of tree) {
    if (root.slug === slug) return root;

    const child = root.children.find((candidate) => candidate.slug === slug);
    if (child) return child;
  }

  return null;
}

function single(value: string | string[] | undefined): string | null {
  return typeof value === "string" && value.trim() !== "" ? value.trim() : null;
}

function countOf(total: number): string {
  return total === 1 ? "1 listing" : `${total.toLocaleString("en-GB")} listings`;
}
