"use client";

import { useState } from "react";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import { loadFresh } from "@/lib/navigation";

/**
 * Signing out.
 *
 * A button rather than a link, because it is a write: Laravel invalidates the
 * session and rotates the CSRF token, and a `GET` that ends a session is one a
 * link prefetch can trigger by accident.
 *
 * **It ends in a full page load, not a client-side navigation.** The first
 * version called `router.refresh()` and then `router.push("/")`, and the
 * end-to-end test caught what that did from the home page: the API answered
 * 204 and ended the session, and the page never noticed. `loadFresh` says why
 * a new document is the right answer at a moment like this one, and names the
 * other moment that turned out to need it.
 *
 * A 401 is treated as success. It means the session had already gone, which is
 * the state this button exists to reach.
 */
export function SignOutButton() {
  const [pending, setPending] = useState(false);

  async function signOut() {
    setPending(true);

    try {
      await apiFetch<void>("/auth/logout", { method: "POST" });
    } catch (error) {
      if (!(error instanceof ApiError) || !error.isUnauthenticated) {
        console.error("Signing out failed.", error);
        setPending(false);

        return;
      }
    }

    loadFresh("/");
  }

  return (
    <button
      type="button"
      onClick={signOut}
      disabled={pending}
      className="hover:text-primary focus-visible:ring-ring rounded-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:opacity-60"
    >
      {pending ? "Signing out..." : "Sign out"}
    </button>
  );
}
