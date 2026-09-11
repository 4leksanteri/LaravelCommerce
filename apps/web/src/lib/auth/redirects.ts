/**
 * Where to send somebody after they sign in.
 *
 * **A redirect target from the URL is attacker-controlled.** `?next=` is how a
 * phishing link sends somebody through a real sign-in page and out to a
 * convincing copy of it, with the trust of having just authenticated on the
 * genuine site. So a target is only honoured when it is a path on this origin.
 *
 * **Decided by parsing, not by inspecting the string.** The first version of
 * this refused `//host` and `/\host` by looking at the leading characters, and
 * its own test found the hole on the first run: browsers strip tabs and
 * newlines out of a URL before parsing it, so `/\t/host` sails past a check
 * for `//` and then navigates to another origin as `//host`. Every such trick
 * is a way the string and the parser disagree, so the parser is asked - the
 * same WHATWG one the browser uses - and the answer has to come back on this
 * origin.
 *
 * Refused, each a real attack rather than tidiness:
 *
 *   https://elsewhere.test    another origin outright
 *   //elsewhere.test          protocol-relative, reads as a path
 *   /\elsewhere.test          the parser treats the backslash as a slash
 *   /\t/elsewhere.test        the parser deletes the tab
 *   javascript:alert(1)       not a navigation at all
 *   not-a-path                relative, resolves against wherever they are
 *
 * A hostile value is refused rather than repaired: rewriting one tends to
 * produce a different hostile value, and sending somebody home costs nothing.
 */
const HOME = "/";

/** Stands in for this origin while parsing. `.invalid` can never resolve. */
const PLACEHOLDER = "http://this-origin.invalid";

export function safeRedirect(target: string | undefined | null): string {
  // Relative paths are refused before parsing, because the parser would
  // happily resolve them - against the placeholder here, and against whatever
  // page the person happens to be on in a browser.
  if (!target || !target.startsWith("/")) {
    return HOME;
  }

  let url: URL;

  try {
    url = new URL(target, PLACEHOLDER);
  } catch {
    return HOME;
  }

  if (url.origin !== PLACEHOLDER) {
    return HOME;
  }

  // What the parser understood, not what was typed. A tab that survived here
  // would be one a browser was about to act on differently.
  return `${url.pathname}${url.search}${url.hash}`;
}
