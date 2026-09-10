import "server-only";

/**
 * Where the Laravel API lives, on the internal Docker network.
 *
 * This module is the only place in the frontend that knows that address, and
 * `server-only` is what keeps it that way: importing it from a Client
 * Component is a build error rather than a hostname quietly shipped to
 * browsers in a JavaScript bundle.
 *
 * The API is not published to the internet. A browser reaches it by calling a
 * relative /api/** URL on this application's own origin, which the route
 * handler in app/api/[...path]/route.ts forwards here. See ADR 0003.
 */

/** Base path Laravel mounts its versioned API on. */
export const API_PREFIX = "/api/v1";

/**
 * Read at call time rather than at module scope.
 *
 * The production image is built once and run with whatever environment it is
 * given, so a value captured while `next build` ran would be the build
 * machine's, baked into the bundle and wrong everywhere it is deployed.
 */
export function apiInternalUrl(): string {
  const url = process.env.API_INTERNAL_URL;

  if (!url) {
    throw new Error(
      "API_INTERNAL_URL is not set. The frontend has no other way to reach the API; " +
        "see .env.example.",
    );
  }

  return url.replace(/\/+$/, "");
}
