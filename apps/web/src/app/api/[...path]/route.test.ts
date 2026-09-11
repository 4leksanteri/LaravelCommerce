// @vitest-environment node
import { NextRequest } from "next/server";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { GET, POST } from "./route";

/**
 * The proxy, which is the code whose failures are silent.
 *
 * ADR 0003 names three behaviours that break authentication without erroring:
 * drop Origin and every request arrives anonymous with a valid cookie attached;
 * merge two Set-Cookie headers and the browser stores neither; pass on the
 * upstream's Content-Encoding and the browser tries to decompress a body that
 * has already been decompressed. None of them produces an exception. Each one
 * produces a person who is mysteriously signed out.
 */

const upstream = vi.fn<typeof fetch>();

beforeEach(() => {
  vi.stubEnv("API_INTERNAL_URL", "http://api:8000/");
  vi.stubGlobal("fetch", upstream);
  upstream.mockResolvedValue(new Response("{}", { status: 200 }));
});

function context(...segments: string[]) {
  return { params: Promise.resolve({ path: segments }) };
}

function forwarded(): { url: string; init: RequestInit & { duplex?: string }; headers: Headers } {
  const [url, init] = upstream.mock.calls[0] as [string, RequestInit & { duplex?: string }];

  return { url, init, headers: new Headers(init.headers) };
}

describe("the request it sends to Laravel", () => {
  it("maps the path and query across unchanged, to the internal address", async () => {
    await GET(
      new NextRequest("http://localhost:3010/api/v1/search?q=lens&page=2"),
      context("v1", "search"),
    );

    // The trailing slash on API_INTERNAL_URL is trimmed rather than doubled.
    expect(forwarded().url).toBe("http://api:8000/api/v1/search?q=lens&page=2");
  });

  it("forwards Origin and Referer untouched, which is what Sanctum authenticates by", async () => {
    await GET(
      new NextRequest("http://localhost:3010/api/v1/auth/me", {
        headers: { origin: "http://localhost:3010", referer: "http://localhost:3010/orders" },
      }),
      context("v1", "auth", "me"),
    );

    expect(forwarded().headers.get("origin")).toBe("http://localhost:3010");
    expect(forwarded().headers.get("referer")).toBe("http://localhost:3010/orders");
  });

  it("forwards the session cookie", async () => {
    await GET(
      new NextRequest("http://localhost:3010/api/v1/cart", {
        headers: { cookie: "laravel-commerce-session=abc; XSRF-TOKEN=def" },
      }),
      context("v1", "cart"),
    );

    expect(forwarded().headers.get("cookie")).toBe("laravel-commerce-session=abc; XSRF-TOKEN=def");
  });

  /**
   * Set rather than merged. A client that sends its own X-Forwarded-Host must
   * not be able to make Laravel believe it is serving another origin - that is
   * the header signed URLs and pagination were built from.
   */
  it("overwrites a forwarded host the client tried to supply", async () => {
    await GET(
      new NextRequest("http://localhost:3010/api/v1/cart", {
        headers: { "x-forwarded-host": "elsewhere.test", "x-forwarded-proto": "https" },
      }),
      context("v1", "cart"),
    );

    expect(forwarded().headers.get("x-forwarded-host")).toBe("localhost:3010");
    expect(forwarded().headers.get("x-forwarded-proto")).toBe("http");
  });

  it("drops headers that describe the hop to this server rather than the next one", async () => {
    await GET(
      new NextRequest("http://localhost:3010/api/v1/cart", {
        headers: {
          "accept-encoding": "gzip, br",
          connection: "keep-alive",
          "x-request-id": "kept",
        },
      }),
      context("v1", "cart"),
    );

    expect(forwarded().headers.get("accept-encoding")).toBeNull();
    expect(forwarded().headers.get("connection")).toBeNull();
    expect(forwarded().headers.get("x-request-id")).toBe("kept");
  });

  it("streams a write's body through, and never follows a redirect or caches", async () => {
    await POST(
      new NextRequest("http://localhost:3010/api/v1/auth/login", {
        method: "POST",
        body: '{"email":"aino@example.test"}',
        headers: { "content-type": "application/json" },
      }),
      context("v1", "auth", "login"),
    );

    const { init } = forwarded();

    expect(init.method).toBe("POST");
    expect(init.duplex).toBe("half");
    expect(await new Response(init.body).text()).toBe('{"email":"aino@example.test"}');
    expect(init.redirect).toBe("manual");
    expect(init.cache).toBe("no-store");
  });
});

describe("the response it hands back to the browser", () => {
  it("keeps every Set-Cookie separate, because a merged pair is two cookies stored as neither", async () => {
    upstream.mockResolvedValue(
      new Response(null, {
        status: 204,
        headers: [
          ["set-cookie", "XSRF-TOKEN=new; Path=/; SameSite=Lax"],
          ["set-cookie", "laravel-commerce-session=rotated; Path=/; HttpOnly; SameSite=Lax"],
        ],
      }),
    );

    const response = await POST(
      new NextRequest("http://localhost:3010/api/v1/auth/login", { method: "POST" }),
      context("v1", "auth", "login"),
    );

    expect(response.headers.getSetCookie()).toEqual([
      "XSRF-TOKEN=new; Path=/; SameSite=Lax",
      "laravel-commerce-session=rotated; Path=/; HttpOnly; SameSite=Lax",
    ]);
  });

  it("drops encoding and length claims about a body that has already been decoded", async () => {
    upstream.mockResolvedValue(
      new Response('{"data":[]}', {
        status: 200,
        headers: {
          "content-type": "application/json",
          "content-encoding": "gzip",
          "content-length": "11",
        },
      }),
    );

    const response = await GET(
      new NextRequest("http://localhost:3010/api/v1/search"),
      context("v1", "search"),
    );

    expect(response.headers.get("content-encoding")).toBeNull();
    expect(response.headers.get("content-length")).toBeNull();
    expect(response.headers.get("content-type")).toBe("application/json");
    expect(await response.text()).toBe('{"data":[]}');
  });

  it("does not advertise the server software or the PHP version", async () => {
    upstream.mockResolvedValue(
      new Response("{}", {
        status: 200,
        headers: { server: "Caddy", "x-powered-by": "PHP/8.5.9" },
      }),
    );

    const response = await GET(
      new NextRequest("http://localhost:3010/api/v1/health"),
      context("v1", "health"),
    );

    expect(response.headers.get("server")).toBeNull();
    expect(response.headers.get("x-powered-by")).toBeNull();
  });

  it("passes the status through, so a 419 or a 409 reaches the page as itself", async () => {
    upstream.mockResolvedValue(new Response('{"message":"CSRF token mismatch."}', { status: 419 }));

    const response = await POST(
      new NextRequest("http://localhost:3010/api/v1/checkout", { method: "POST" }),
      context("v1", "checkout"),
    );

    expect(response.status).toBe(419);
  });
});
