import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "./client";
import { ApiError } from "./errors";

/**
 * CSRF, and the one mistake that makes it fail on the first write after
 * signing in: caching the token. Laravel rotates it when the session
 * regenerates, so a value captured once is a 419 exactly when somebody has
 * just been let in.
 */

const network = vi.fn<typeof fetch>();

function setCookie(value: string) {
  document.cookie = `XSRF-TOKEN=${value}; path=/`;
}

function clearCookie() {
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
}

function sent(call = 0): { url: string; headers: Headers; init: RequestInit } {
  const [url, init] = network.mock.calls[call] as [string, RequestInit];

  return { url, init, headers: new Headers(init.headers) };
}

beforeEach(() => {
  vi.stubGlobal("fetch", network);

  // A fresh Response per call. A body can be read once, so one shared object
  // makes the second request in a test fail on the mock rather than the code.
  network.mockImplementation(
    async () =>
      new Response("{}", { status: 200, headers: { "content-type": "application/json" } }),
  );
});

afterEach(clearCookie);

describe("apiFetch", () => {
  it("calls a relative path on this origin, never the API directly", async () => {
    await apiFetch("/cart");

    expect(sent().url).toBe("/api/v1/cart");
    expect(sent().init.credentials).toBe("same-origin");
    expect(sent().headers.get("accept")).toBe("application/json");
  });

  it("sends no CSRF token on a read", async () => {
    setCookie("token");

    await apiFetch("/cart");

    expect(sent().headers.get("x-xsrf-token")).toBeNull();
  });

  it("sends the token decoded, because Laravel percent-encodes the cookie and compares it decoded", async () => {
    setCookie("abc%3D%3D");

    await apiFetch("/cart/items", { method: "POST" });

    expect(sent().headers.get("x-xsrf-token")).toBe("abc==");
  });

  it("reads the cookie again for every write rather than caching it", async () => {
    setCookie("before-sign-in");
    await apiFetch("/auth/login", { method: "POST" });

    // What Laravel does on sign-in: regenerate the session, rotate the token.
    setCookie("after-sign-in");
    await apiFetch("/cart/items", { method: "POST" });

    expect(sent(0).headers.get("x-xsrf-token")).toBe("before-sign-in");
    expect(sent(1).headers.get("x-xsrf-token")).toBe("after-sign-in");
  });

  it("asks Laravel for a token first when there is none yet", async () => {
    network.mockImplementation(async (input) => {
      if (String(input) === "/api/v1/auth/csrf-cookie") {
        setCookie("fresh");

        return new Response(null, { status: 204 });
      }

      return new Response(null, { status: 204 });
    });

    await apiFetch("/auth/register", { method: "POST" });

    expect(sent(0).url).toBe("/api/v1/auth/csrf-cookie");
    expect(sent(1).url).toBe("/api/v1/auth/register");
    expect(sent(1).headers.get("x-xsrf-token")).toBe("fresh");
  });

  it("refuses to send a write it knows will be a 419", async () => {
    network.mockResolvedValue(new Response(null, { status: 204 }));

    await expect(apiFetch("/auth/login", { method: "POST" })).rejects.toThrow(/XSRF-TOKEN/);
  });

  it("carries a refusal's status and body rather than swallowing them", async () => {
    setCookie("token");
    network.mockResolvedValue(
      new Response('{"message":"The given data was invalid.","errors":{"email":["Taken."]}}', {
        status: 422,
        headers: { "content-type": "application/json" },
      }),
    );

    const refusal = await apiFetch("/auth/register", { method: "POST" }).catch(
      (error: unknown) => error,
    );

    expect(refusal).toBeInstanceOf(ApiError);
    expect((refusal as ApiError).status).toBe(422);
    expect((refusal as ApiError).validationErrors).toEqual({ email: ["Taken."] });
  });

  it("answers a 204 with nothing rather than trying to parse it", async () => {
    setCookie("token");
    network.mockResolvedValue(new Response(null, { status: 204 }));

    await expect(apiFetch("/auth/logout", { method: "POST" })).resolves.toBeUndefined();
  });
});
