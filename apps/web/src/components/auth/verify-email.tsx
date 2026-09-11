"use client";

import { useEffect, useRef, useState } from "react";

import { Alert } from "@/components/ui/alert";
import { TextLink } from "@/components/ui/text-link";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { EmailVerificationResult } from "@/lib/api/types";

/**
 * Confirming an address, from the link in the email.
 *
 * **The query string is rebuilt by hand, in a fixed order.** Laravel verifies a
 * signature by taking the raw query string, dropping `signature`, rejoining
 * what is left **in the order the request sent it**, and hashing that. Today
 * only `expires` is left, so the order of the pair happens not to matter - and
 * that is a property of there being exactly two parameters rather than a
 * guarantee. A third would make it matter immediately, and the failure would be
 * a link that is valid everywhere it is built one way and 403 everywhere it is
 * built the other.
 *
 * So the parameters are appended explicitly rather than handed to
 * `URLSearchParams` from an object, whose order follows however the object was
 * assembled. The API builds it the same way, and `VerifyEmailTest` walks the
 * real notification end to end; this is the other half of that round trip.
 *
 * The signature covers the path and query only - it is a **relative** signed
 * URL - so it survives the proxy rewriting the host, which an absolute one
 * would not (ADR 0002).
 *
 * Verification runs on mount rather than behind a button, because a person who
 * has just clicked a link in their inbox has already pressed the button. The
 * ref guard stops the effect firing twice in development, where React mounts
 * components twice on purpose.
 *
 * Clicking twice is safe regardless, and not by accident: the endpoint is
 * idempotent and says which happened. Mail clients prefetch links, so a second
 * call is the expected case rather than the odd one, and `already_verified` is
 * reported as the success it is instead of as a failure.
 */
type Outcome =
  | { state: "checking" }
  | { state: "verified"; message: string }
  | { state: "refused"; message: string };

export function VerifyEmail({
  id,
  hash,
  expires,
  signature,
}: {
  id: string;
  hash: string;
  expires: string;
  signature: string;
}) {
  const [outcome, setOutcome] = useState<Outcome>({ state: "checking" });
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    const path =
      `/auth/email/verify/${encodeURIComponent(id)}/${encodeURIComponent(hash)}` +
      `?expires=${encodeURIComponent(expires)}&signature=${encodeURIComponent(signature)}`;

    apiFetch<EmailVerificationResult>(path)
      .then((result) =>
        setOutcome({
          state: "verified",
          message: result.already_verified
            ? "That address was already confirmed. Nothing to do."
            : "Your email address is confirmed.",
        }),
      )
      .catch((error: unknown) => {
        if (error instanceof ApiError && error.isForbidden) {
          setOutcome({
            state: "refused",
            message: "This link has expired or has already been used.",
          });

          return;
        }

        console.error("Verifying the email address failed.", error);

        setOutcome({
          state: "refused",
          message: "We could not confirm the address just now. Try the link again in a moment.",
        });
      });
  }, [id, hash, expires, signature]);

  if (outcome.state === "checking") {
    return (
      <p className="text-muted-foreground text-sm" role="status">
        Confirming your address...
      </p>
    );
  }

  if (outcome.state === "verified") {
    return (
      <div className="space-y-4">
        <Alert tone="positive">{outcome.message}</Alert>
        <TextLink href="/">Start browsing</TextLink>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <Alert tone="danger">{outcome.message}</Alert>
      <p className="text-muted-foreground text-sm">
        <TextLink href="/verify-email/sent">Send another link</TextLink> to try again.
      </p>
    </div>
  );
}
