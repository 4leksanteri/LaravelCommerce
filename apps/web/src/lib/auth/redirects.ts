/**
 * Where to send somebody after they sign in.
 *
 * **A redirect target from the URL is attacker-controlled.** `?next=` is how a
 * phishing link sends somebody through a real sign-in page and out to a
 * convincing copy of it, with the trust of having just authenticated on the
 * genuine site. So a target is only honoured when it is a path on this origin.
 *
 * Rejected, and each of these is a real attack rather than tidiness:
 *
 *   https://elsewhere.test    another origin outright
 *   //elsewhere.test          protocol-relative, which a browser resolves as
 *                             another origin while it reads as a path
 *   /\elsewhere.test          backslashes, which some browsers normalise to
 *                             forward slashes and turn into the case above
 *   not-a-path                relative, so it resolves against wherever the
 *                             person happens to be
 */
const HOME = "/";

export function safeRedirect(target: string | undefined | null): string {
  if (!target) return HOME;

  // Anything that is not plainly a single-slash path is refused rather than
  // repaired. Rewriting a hostile value tends to produce a different hostile
  // value; there is no cost to sending somebody home instead.
  if (!target.startsWith("/") || target.startsWith("//") || target.startsWith("/\\")) {
    return HOME;
  }

  return target;
}
