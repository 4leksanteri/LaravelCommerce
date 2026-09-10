import { NextResponse } from "next/server";

/**
 * Liveness for whatever is supervising this container.
 *
 * It answers one question - is this Node process serving HTTP - and it
 * deliberately answers no others.
 *
 * In particular **it does not check the API.** A probe that fails when a
 * dependency is slow takes a healthy process out of rotation for somebody
 * else's problem, and under an orchestrator that means restarting a container
 * that was never broken. The API has its own probe, on its own service. This
 * is the same rule `HealthTest` asserts on the Laravel side, from the other
 * direction.
 *
 * Nor does it probe `/`. That was the original arrangement and it was wrong in
 * both directions: the home page server-renders and calls the API, so every
 * check cost a full render plus a round trip, and it still returned 200 when
 * the API was unreachable because the page catches that and says so. It also
 * tied the probe to a route whose behaviour is expected to change - a redirect
 * or an auth guard on `/` would have marked a healthy container unhealthy.
 */

// Evaluated per request rather than answered from a build-time cache. A cached
// probe is a probe that can pass without the runtime being asked anything.
export const dynamic = "force-dynamic";
export const runtime = "nodejs";

export function GET() {
  return NextResponse.json(
    { status: "ok" },
    {
      // Nothing between here and the prober may answer on its behalf.
      headers: { "cache-control": "no-store, no-cache, must-revalidate" },
    },
  );
}
