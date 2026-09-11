/**
 * "1 listing", "24 listings", "1,204 listings".
 *
 * `total` is the API's count from `meta` (ADR 0022) - this only words it. The
 * locale is fixed for the reason `formatMoney` gives: a server and a browser
 * that disagreed about a thousands separator would be a hydration mismatch.
 */
export function listingCount(total: number): string {
  return total === 1 ? "1 listing" : `${total.toLocaleString("en-GB")} listings`;
}
