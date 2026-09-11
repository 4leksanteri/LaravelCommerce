/**
 * A date, as a person reads it: "4 Mar 2026".
 *
 * **In UTC, on purpose.** The API knows nothing about where anybody is, and
 * this runs on the server, whose own zone is whatever the container was given.
 * Choosing a zone for the reader would be a guess, and a guess that differed
 * between the server's render and a client component's would be a hydration
 * mismatch. UTC is the one zone that is the same everywhere and does not
 * pretend to be the reader's.
 *
 * The cost is a date that is a day out for somebody far from Greenwich in the
 * last hours of their evening. Nothing here is shown to the minute, and the
 * dates that matter - when an order completes on its own - are days away
 * (ADR 0032).
 *
 * The locale is fixed for the reason `formatMoney`'s is: the server and the
 * browser have to agree, or React complains and the page flickers.
 */
const DATE = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "short",
  year: "numeric",
  timeZone: "UTC",
});

export function formatDate(iso: string): string {
  return DATE.format(new Date(iso));
}
