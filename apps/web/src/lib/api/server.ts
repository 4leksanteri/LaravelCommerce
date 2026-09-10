import "server-only";

import { headers } from "next/headers";

import { API_PREFIX, apiInternalUrl } from "./config";
import { ApiError, readBody } from "./errors";

/**
 * The API client for Server Components, Server Actions and route handlers.
 *
 * It calls Laravel directly over the internal network rather than going
 * through this application's own /api proxy. Proxying would mean this server
 * making an HTTP request to itself to reach a service it can already see: an
 * extra hop, and a way to exhaust the request pool under load.
 *
 * Two things have to be reconstructed by hand as a result, because there is no
 * browser on this side of the call.
 *
 *   Cookie   The session belongs to the person whose page is being rendered,
 *            not to this process. It arrives on the incoming request and is
 *            forwarded from there.
 *   Origin   Sanctum will not honour a session cookie unless Origin or Referer
 *            matches SANCTUM_STATEFUL_DOMAINS. A server-side fetch sends
 *            neither, so a rendered page would come back anonymous while the
 *            same call from the browser succeeded.
 *
 * See ADR 0003.
 */

const UNSAFE_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);

/**
 * Where the browser thinks it is. Derived from the incoming request rather
 * than configured, so one deployment behind a load balancer and one on a
 * laptop both get it right without a second variable to keep in step.
 */
async function publicOrigin(incoming: Headers): Promise<string> {
  const host = incoming.get("x-forwarded-host") ?? incoming.get("host");
  const proto = incoming.get("x-forwarded-proto") ?? "http";

  if (!host) {
    throw new Error(
      "The incoming request carries no Host header, so the public origin cannot be " +
        "determined and Sanctum would refuse the session cookie.",
    );
  }

  return `${proto}://${host}`;
}

export async function serverFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const incoming = await headers();
  const origin = await publicOrigin(incoming);

  const method = (init.method ?? "GET").toUpperCase();
  const requestHeaders = new Headers(init.headers);

  requestHeaders.set("accept", "application/json");
  requestHeaders.set("origin", origin);
  requestHeaders.set("referer", `${origin}/`);
  requestHeaders.set("x-forwarded-host", new URL(origin).host);
  requestHeaders.set("x-forwarded-proto", new URL(origin).protocol.replace(/:$/, ""));

  const cookie = incoming.get("cookie");
  if (cookie) {
    requestHeaders.set("cookie", cookie);
  }

  if (UNSAFE_METHODS.has(method)) {
    // Laravel rotates the CSRF token, including on login, so the cookie is
    // read for each write rather than captured once.
    const token = readXsrfToken(cookie);

    if (token) {
      requestHeaders.set("x-xsrf-token", token);
    }
  }

  const response = await fetch(`${apiInternalUrl()}${API_PREFIX}${path}`, {
    ...init,
    method,
    headers: requestHeaders,
    // A page rendered for one signed-in person must never be served to
    // another. Nothing behind a session is cacheable by default.
    cache: "no-store",
  });

  if (!response.ok) {
    throw new ApiError(response.status, await readBody(response));
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

function readXsrfToken(cookieHeader: string | null): string | null {
  if (!cookieHeader) return null;

  for (const part of cookieHeader.split(";")) {
    const [name, ...rest] = part.trim().split("=");

    if (name === "XSRF-TOKEN") {
      return decodeURIComponent(rest.join("="));
    }
  }

  return null;
}
