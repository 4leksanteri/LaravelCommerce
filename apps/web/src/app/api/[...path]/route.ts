import type { NextRequest } from "next/server";

import { apiInternalUrl } from "@/lib/api/config";

/**
 * Reverse proxy for the Laravel API.
 *
 * This application's server is the only thing published to the internet. The
 * browser calls relative /api/** URLs on this origin, so from its point of
 * view the site and the API are the same origin: there is no CORS to
 * configure, and Laravel's session cookie is first-party and HttpOnly.
 *
 * Laravel mounts its routes under /api too, so the path maps across unchanged.
 *
 * Three behaviours here are load-bearing. Changing any of them silently breaks
 * authentication rather than failing loudly, so read ADR 0003 before editing.
 *
 *   1. Origin and Referer are forwarded untouched. Sanctum decides whether a
 *      request may be authenticated by a session cookie by matching one of
 *      those two against SANCTUM_STATEFUL_DOMAINS. Drop them and every request
 *      arrives anonymous, with a perfectly valid session cookie attached.
 *   2. Set-Cookie is forwarded, all of them. Laravel rotates the session on
 *      login and rotates the CSRF token with it, so losing one response's
 *      cookies logs the person out on their next write.
 *   3. Content-Encoding and Content-Length are dropped from the response. The
 *      fetch below has already decoded the body, so passing the upstream's
 *      claims about it on to the browser describes a body that no longer
 *      exists.
 */

// The proxy holds a session's cookies. Caching one person's response and
// serving it to somebody else is the failure this forbids.
export const dynamic = "force-dynamic";
export const runtime = "nodejs";
export const fetchCache = "force-no-store";

/**
 * Connection-scoped headers. They describe the hop between the browser and
 * this server and are meaningless, or actively wrong, on the next hop.
 * `host` is excluded because the target host is the API's, not this one's.
 */
const HOP_BY_HOP_HEADERS = new Set([
  "connection",
  "keep-alive",
  "proxy-authenticate",
  "proxy-authorization",
  "te",
  "trailer",
  "transfer-encoding",
  "upgrade",
  "host",
  "content-length",
  // Not hop-by-hop, but dropped deliberately: letting the API compress its
  // reply only means undici decompresses it again here, and every mismatch
  // between the body and its headers below starts with this one.
  "accept-encoding",
]);

const STRIPPED_RESPONSE_HEADERS = new Set([
  "connection",
  "keep-alive",
  "transfer-encoding",
  "content-encoding",
  "content-length",
  // Advertises the exact server software and PHP version. Nothing downstream
  // reads it, and it only helps somebody match a known CVE to this deployment.
  "server",
  "x-powered-by",
]);

async function proxy(request: NextRequest, path: string[]): Promise<Response> {
  const target = `${apiInternalUrl()}/api/${path.join("/")}${request.nextUrl.search}`;

  const headers = new Headers();
  for (const [name, value] of request.headers) {
    if (!HOP_BY_HOP_HEADERS.has(name.toLowerCase())) {
      headers.set(name, value);
    }
  }

  // Set rather than merged, so a client-supplied value cannot survive and
  // spoof the origin Laravel believes it is serving.
  headers.set("x-forwarded-host", request.nextUrl.host);
  headers.set("x-forwarded-proto", request.nextUrl.protocol.replace(/:$/, ""));

  const hasBody = request.method !== "GET" && request.method !== "HEAD";

  const upstream = await fetch(target, {
    method: request.method,
    headers,
    // Streamed rather than buffered, so a product image does not have to sit
    // in this process's memory in full before the API sees any of it. Node
    // requires `duplex: "half"` to accept a stream as a request body, and the
    // fetch types have not caught up with that yet.
    body: hasBody ? request.body : undefined,
    duplex: hasBody ? "half" : undefined,
    redirect: "manual",
    cache: "no-store",
  } as RequestInit & { duplex?: "half" });

  const responseHeaders = new Headers();
  for (const [name, value] of upstream.headers) {
    if (!STRIPPED_RESPONSE_HEADERS.has(name.toLowerCase()) && name.toLowerCase() !== "set-cookie") {
      responseHeaders.set(name, value);
    }
  }

  // Set-Cookie is the one header that legitimately appears more than once, and
  // iterating Headers above collapses repeats into a single comma-joined
  // value. A session cookie and a CSRF cookie merged into one string is two
  // cookies the browser stores as neither.
  for (const cookie of upstream.headers.getSetCookie()) {
    responseHeaders.append("set-cookie", cookie);
  }

  return new Response(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers: responseHeaders,
  });
}

type RouteContext = { params: Promise<{ path: string[] }> };

async function handler(request: NextRequest, context: RouteContext): Promise<Response> {
  const { path } = await context.params;

  return proxy(request, path);
}

export {
  handler as DELETE,
  handler as GET,
  handler as HEAD,
  handler as OPTIONS,
  handler as PATCH,
  handler as POST,
  handler as PUT,
};
