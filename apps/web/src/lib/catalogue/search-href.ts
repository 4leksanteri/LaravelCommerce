/**
 * The address of a search, built one way everywhere.
 *
 * Every link on the results page - a category filter, a page number, "search
 * all categories instead" - is the current search with one thing changed.
 * Built by hand in each place, one of them eventually drops the query or keeps
 * a stale page number, and somebody filtering by category lands on page 4 of
 * something that has two.
 *
 * The parameters always appear in the same order and page 1 is never written,
 * so one search has one address. Two spellings of the same page are two
 * entries in a browser's history and two things to compare in a test.
 */
export type SearchQuery = {
  q?: string | null;
  category?: string | null;
  page?: number | null;
};

export function searchHref({ q, category, page }: SearchQuery): string {
  const params = new URLSearchParams();
  const term = q?.trim();

  if (term) {
    params.set("q", term);
  }

  if (category) {
    params.set("category", category);
  }

  if (page && page > 1) {
    params.set("page", String(page));
  }

  const query = params.toString();

  return query ? `/search?${query}` : "/search";
}
