"use client";

import { useState } from "react";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

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
 * 204 and ended the session, and the page never noticed. Refreshing and then
 * pushing to the URL it was already on left the page as it was, so the header
 * went on offering "Orders" and a disabled "Signing out..." to somebody who was
 * no longer signed in. Only a real browser could have seen that; every earlier
 * check had called the API directly.
 *
 * A full load is right for more than that one case. The client router holds on
 * to what it has rendered, for back and forward navigation among other things,
 * and all of it was rendered for the person who just left. Signing out is the
 * moment every piece of that should be discarded, and a new document is the
 * only thing that guarantees it.
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

    // The rule's advice is `router.push()`, which is the approach the
    // end-to-end test caught failing here. See the comment above.
    // eslint-disable-next-line @next/next/no-location-assign-relative-destination -- deliberate
    window.location.assign("/");
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
