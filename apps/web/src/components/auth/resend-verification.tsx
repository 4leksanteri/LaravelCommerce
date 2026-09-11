"use client";

import { useState } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { apiFetch } from "@/lib/api/client";
import { useApiSubmit } from "@/hooks/use-api-submit";

/**
 * Asking for another verification email.
 *
 * The endpoint needs a session - it sends to the signed-in account's address
 * and takes no address of its own, which is what stops it being a way to post
 * mail to strangers. A person who is signed out gets a 401, and the hook turns
 * that into "your session has ended"; the page around this offers them a way
 * back to sign in.
 */
export function ResendVerification() {
  const { pending, failure, submit } = useApiSubmit();
  const [sent, setSent] = useState(false);

  async function resend() {
    await submit(async () => {
      await apiFetch<void>("/auth/email/resend", { method: "POST" });

      setSent(true);
    });
  }

  return (
    <div className="space-y-3">
      {failure ? <Alert tone="danger">{failure}</Alert> : null}
      {sent ? <Alert tone="positive">Sent. Give it a minute to arrive.</Alert> : null}

      <Button variant="secondary" size="block" onClick={resend} disabled={pending}>
        {pending ? "Sending..." : sent ? "Send it again" : "Resend the email"}
      </Button>
    </div>
  );
}
