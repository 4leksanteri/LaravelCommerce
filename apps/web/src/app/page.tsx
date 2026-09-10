import { unstable_rethrow } from "next/navigation";

import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { HealthStatus } from "@/lib/api/types";

/**
 * Placeholder home page, and a working proof of the one thing the repository
 * is built around: this is a Server Component reaching Laravel over the
 * internal network, on a host the browser cannot resolve and does not know.
 *
 * Replace it with the marketplace's own home page. Keep the pattern - render
 * on the server from data the server fetched.
 */

type ApiStatus = { reachable: true } | { reachable: false; reason: string };

async function readApiStatus(): Promise<ApiStatus> {
  try {
    const health = await serverFetch<HealthStatus>("/health");

    return health.status === "ok"
      ? { reachable: true }
      : { reachable: false, reason: "Unexpected reply" };
  } catch (error) {
    // Next signals control flow by throwing: notFound(), redirect(), and the
    // DYNAMIC_SERVER_USAGE marker that says a route read headers and therefore
    // cannot be prerendered. Swallowing one turns a working mechanism into a
    // silent bug - this page rendered "API unreachable" during `next build`
    // until this line existed. Every catch around a server fetch needs it.
    unstable_rethrow(error);

    // The detail goes to the server log, not to the page. What went wrong
    // reaching an internal service is not a visitor's business.
    console.error("The API health check failed.", error);

    return {
      reachable: false,
      reason: error instanceof ApiError ? `Responded ${error.status}` : "No response",
    };
  }
}

export default async function Home() {
  const status = await readApiStatus();

  return (
    <main className="mx-auto flex min-h-full w-full max-w-2xl flex-1 flex-col justify-center gap-8 px-6 py-16">
      <div className="space-y-3">
        <h1 className="text-2xl font-semibold tracking-tight">Laravel Commerce</h1>
        <p className="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
          A marketplace. Next.js renders and decides nothing; Laravel owns the database, the domain
          and every rule. The browser never reaches the API directly.
        </p>
      </div>

      <dl className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-800 dark:border-zinc-800">
        <div className="flex items-center justify-between gap-4 px-4 py-3">
          <dt className="text-zinc-600 dark:text-zinc-400">Web</dt>
          <dd className="font-medium">Serving</dd>
        </div>
        <div className="flex items-center justify-between gap-4 px-4 py-3">
          <dt className="text-zinc-600 dark:text-zinc-400">API, over the internal network</dt>
          <dd
            className={
              status.reachable
                ? "font-medium text-emerald-700 dark:text-emerald-400"
                : "font-medium text-red-700 dark:text-red-400"
            }
          >
            {status.reachable ? "Reachable" : status.reason}
          </dd>
        </div>
      </dl>

      <p className="text-xs leading-relaxed text-zinc-500">
        Read <code className="font-mono">CLAUDE.md</code> before making changes, and{" "}
        <code className="font-mono">docs/architecture/</code> for why the boundary is where it is.
      </p>
    </main>
  );
}
