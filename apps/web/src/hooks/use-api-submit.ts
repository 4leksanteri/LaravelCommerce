"use client";

import { useCallback, useState } from "react";

import { ApiError } from "@/lib/api/errors";
import type { ValidationErrors } from "@/lib/api/types";

/**
 * Submitting a form to the API, and turning a refusal into something to render.
 *
 * Five auth screens do exactly the same thing with a failure, and doing it
 * five times is how four of them end up right. The statuses are not
 * interchangeable (`apps/web/CLAUDE.md` section 9): 422 is field messages, 401
 * and 403 mean different things to a person, and a generic "something went
 * wrong" for all of them makes a real interaction wrong.
 *
 * Submission is handled explicitly rather than by handing the `<form>` element
 * an action, because **a refusal must not cost somebody what they typed.**
 * Somebody whose password was wrong should find their email address still in
 * the field, and the surest way to guarantee that is to own the values.
 */
export type Submission = {
  pending: boolean;
  /** Laravel's 422 body, keyed by field. Empty when there is nothing to say. */
  fieldErrors: ValidationErrors;
  /** A refusal with no field to hang it on. */
  failure: string | null;
  submit: (work: () => Promise<void>) => Promise<void>;
};

export function useApiSubmit(): Submission {
  const [pending, setPending] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<ValidationErrors>({});
  const [failure, setFailure] = useState<string | null>(null);

  const submit = useCallback(async (work: () => Promise<void>) => {
    setPending(true);
    setFieldErrors({});
    setFailure(null);

    try {
      await work();
    } catch (error) {
      const refusal = describe(error);

      setFieldErrors(refusal.fieldErrors);
      setFailure(refusal.failure);
    } finally {
      setPending(false);
    }
  }, []);

  return { pending, fieldErrors, failure, submit };
}

type Refusal = { fieldErrors: ValidationErrors; failure: string | null };

function describe(error: unknown): Refusal {
  if (!(error instanceof ApiError)) {
    // A network failure, or this application throwing. The detail goes to the
    // console rather than the page: what went wrong reaching an internal
    // service is not a visitor's business.
    console.error("The request did not reach the API.", error);

    return { fieldErrors: {}, failure: "We could not reach the server. Try again in a moment." };
  }

  const validation = error.validationErrors;

  if (validation) {
    // The 422 body carries a summary as well as the field messages. Laravel's
    // summary restates the first field message, so showing both would say the
    // same thing twice - the fields have it.
    return { fieldErrors: validation, failure: null };
  }

  return { fieldErrors: {}, failure: messageFor(error) };
}

function messageFor(error: ApiError): string {
  switch (error.status) {
    case 401:
      return "Your session has ended. Sign in and try again.";
    case 403: {
      // Not allowed. Which rule said no is the API's to say - an unconfirmed
      // address at checkout, a link that no longer works - so its message is
      // shown where there is one. This used to be a sentence written for the
      // password-reset screen, and would have told somebody at checkout that
      // their link had expired.
      const message = (error.body as { message?: unknown } | null)?.message;

      return typeof message === "string" && message !== ""
        ? message
        : "You are not allowed to do that.";
    }
    case 409: {
      // Allowed, but the state says no: sold out, only two left, no longer for
      // sale. The API says which in its message, and that sentence is the next
      // step the person needs (root CLAUDE.md section 8), so it is shown as
      // sent rather than replaced with a generic one.
      const message = (error.body as { message?: unknown } | null)?.message;

      return typeof message === "string" && message !== ""
        ? message
        : "That can no longer be done. Reload the page to see why.";
    }
    case 419:
      // Laravel's CSRF refusal. `apiFetch` fetches a token per write, so this
      // is a session that expired between loading the page and submitting it.
      return "This page has been open a while. Reload it and try again.";
    case 429:
      return "Too many attempts. Wait a minute and try again.";
    default:
      console.error("The API refused the request.", error.status, error.body);

      return "Something went wrong at our end. Try again in a moment.";
  }
}
