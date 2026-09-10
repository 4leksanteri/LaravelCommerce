import { ApiError, readBody } from "./errors";

/**
 * The API client for browser code.
 *
 * Every call is a relative URL on this application's own origin. The route
 * handler at app/api/[...path] forwards it to Laravel, which the browser can
 * neither see nor address. That is deliberate: never write an absolute API URL
 * here, and never import anything from lib/api/config or lib/api/server - both
 * are server-only and would take the internal hostname into the bundle.
 *
 * The session cookie is HttpOnly and belongs to Laravel. This module cannot
 * read it, and does not need to: it is same-origin, so the browser attaches it
 * on its own.
 */

const API_PREFIX = "/api/v1";

const UNSAFE_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);

export async function apiFetch<T>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? "GET").toUpperCase();
  const headers = new Headers(init.headers);

  headers.set("accept", "application/json");

  if (UNSAFE_METHODS.has(method)) {
    headers.set("x-xsrf-token", await csrfToken());
  }

  const response = await fetch(`${API_PREFIX}${path}`, {
    ...init,
    method,
    headers,
    // Same-origin, so this is what the browser would do anyway. It is written
    // out because the whole design rests on the cookie travelling.
    credentials: "same-origin",
  });

  if (!response.ok) {
    throw new ApiError(response.status, await readBody(response));
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

/**
 * The CSRF token for this write.
 *
 * Read from the cookie on every unsafe request and never cached. Laravel
 * rotates the token - notably on login, where the session is regenerated - and
 * a value captured once is a 419 on the first write after signing in.
 */
async function csrfToken(): Promise<string> {
  const existing = readCookie("XSRF-TOKEN");
  if (existing) return existing;

  // Laravel sets the cookie here. Under the same-origin proxy this is an
  // ordinary same-origin GET, so the response's Set-Cookie is stored before
  // the write below reads it back.
  await fetch(`${API_PREFIX}/auth/csrf-cookie`, {
    credentials: "same-origin",
    cache: "no-store",
  });

  const token = readCookie("XSRF-TOKEN");

  if (!token) {
    throw new Error(
      "The API did not set an XSRF-TOKEN cookie. The request would be refused with a 419.",
    );
  }

  return token;
}

function readCookie(name: string): string | null {
  for (const part of document.cookie.split(";")) {
    const [key, ...rest] = part.trim().split("=");

    if (key === name) {
      // Laravel percent-encodes the value; Laravel decodes it back before
      // comparing, so it has to be sent decoded.
      return decodeURIComponent(rest.join("="));
    }
  }

  return null;
}
