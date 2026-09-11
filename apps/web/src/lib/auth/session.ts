import "server-only";

import { redirect, unstable_rethrow } from "next/navigation";

import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { AuthenticatedUser, Resource } from "@/lib/api/types";

/**
 * Who is signed in, as far as the API is concerned.
 *
 * **The session is Laravel's and this is the only way to ask about it.** There
 * is no session state in this application: the cookie is HttpOnly and belongs
 * to the API, so "am I signed in" is a question with one answer and it is not
 * held here. Anything else would be a second copy that can disagree, and the
 * copy in the browser is the one an attacker controls.
 *
 * A 401 is the answer "nobody", not a failure. Every other refusal is a real
 * problem and is left to throw, because rendering a page as though nobody is
 * signed in when the API is merely broken would quietly sign people out.
 */
export async function currentUser(): Promise<AuthenticatedUser | null> {
  try {
    const response = await serverFetch<Resource<AuthenticatedUser>>("/auth/me");

    return response.data;
  } catch (error) {
    // Next signals notFound(), redirect() and "this route read headers" by
    // throwing. A catch that swallows one turns a working mechanism into a
    // silent bug, so every catch around a server fetch rethrows first.
    unstable_rethrow(error);

    if (error instanceof ApiError && error.isUnauthenticated) {
      return null;
    }

    throw error;
  }
}

/**
 * The person signed in, or a trip to sign in and come back.
 *
 * **For a page that is nothing without a session** - the cart, the page saying
 * a confirmation email is on its way. A page that is merely better with one
 * draws a way to sign in in place of its one action instead (ADR 0028); this is
 * for the other kind, and redirecting beats rendering an empty shell that can
 * only say "sign in".
 *
 * Extracted when the cart became the second page to do this inline. `next` is
 * checked by `safeRedirect` on the far side like any other return address, so
 * it cannot be used to send somebody off the site.
 *
 * This is an answer to "who is looking", and nothing more. It decides no
 * permission: what the person may do is still the API's to refuse.
 */
export async function requireUser(next: string): Promise<AuthenticatedUser> {
  const user = await currentUser();

  if (!user) {
    redirect(`/login?next=${encodeURIComponent(next)}`);
  }

  return user;
}
