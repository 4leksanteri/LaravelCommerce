/**
 * A full page load, for the moments when everything already drawn should go.
 *
 * **A client-side navigation keeps the layout as it was drawn.** Next fetches
 * the page that changed and leaves the layout around it alone - and the header,
 * with the cart's count and who is signed in, lives in the layout. That is
 * right almost always, and wrong at exactly two moments so far, both found by
 * the end-to-end suite:
 *
 *   signing out        the header went on offering "Orders" and "Signing
 *                      out..." to somebody the API had signed out (ADR 0025)
 *   placing orders     the confirmation was drawn under a header still saying
 *                      "Cart, 2 items" over an empty basket (ADR 0030)
 *
 * Both are transaction boundaries: afterwards, every render the client router
 * is holding was made for a state that no longer exists. A new document is the
 * one thing that guarantees none of it survives.
 *
 * In its own module so the reasoning lives in one place, and so a unit test can
 * replace it, because jsdom cannot navigate. ESLint's rule against a relative
 * `location.assign` recommends `router.push()` - the approach that failed both
 * times - but it only fires on a literal destination, and here the destination
 * is a parameter, so there is no exception to disable.
 */
export function loadFresh(path: string): void {
  window.location.assign(path);
}
