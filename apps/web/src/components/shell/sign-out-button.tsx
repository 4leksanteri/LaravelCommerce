"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";

/**
 * Signing out, which until now nothing in the application did.
 *
 * A button rather than a link, because it is a write: Laravel invalidates the
 * session and rotates the CSRF token, and a `GET` that ends a session is one a
 * link prefetch can trigger by accident.
 *
 * `router.refresh()` before navigating, because every Server Component on the
 * page was rendered for somebody who was signed in - the header most of all.
 *
 * A 401 is treated as success. It means the session had already gone, which is
 * the state this button exists to reach.
 */
export function SignOutButton() {
  const router = useRouter();
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

    router.refresh();
    router.push("/");
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
