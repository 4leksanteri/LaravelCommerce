/**
 * The address of a category, and of a page within it.
 *
 * Page 1 is never written, for the reason `searchHref` gives: one place has one
 * address, and `/categories/audio` and `/categories/audio?page=1` would
 * otherwise be two entries in a browser's history for the same page.
 */
export function categoryHref(slug: string, page?: number | null): string {
  const path = `/categories/${encodeURIComponent(slug)}`;

  return page && page > 1 ? `${path}?page=${page}` : path;
}
