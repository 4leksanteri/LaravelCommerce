"use client";

import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { loadFresh } from "@/lib/navigation";

/**
 * Closing the account for good (ADR 0058).
 *
 * **It asks first, and the first step says what it does.** Every other form on
 * this page can be undone by whoever owns the inbox; this one cannot be undone
 * at all, so the button that starts it is not the button that does it.
 *
 * **It says what stays, before rather than after.** Orders and public reviews
 * are kept, and somebody deciding whether to close an account should know that
 * while deciding - not discover it in the mail afterwards.
 *
 * **A refusal is the API's sentence.** The account cannot be closed while an
 * order is unfinished, a refund is owed, a dispute is being decided or a shop
 * is open, and each 409 names which - so this shows what arrived rather than
 * re-deriving any of it from an order list the browser would have to fetch.
 *
 * It ends in a full page load. The session is destroyed server-side, so every
 * render the client router is holding belongs to somebody who is no longer
 * signed in - the same reasoning `loadFresh` gives for signing out.
 */
export function CloseAccount() {
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [asking, setAsking] = useState(false);
  const [password, setPassword] = useState("");

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    await submit(async () => {
      await apiFetch<void>("/account", {
        method: "DELETE",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ current_password: password }),
      });

      loadFresh("/");
    });
  }

  if (!asking) {
    return (
      <div className="space-y-3">
        <p className="text-muted-foreground text-sm leading-relaxed">
          Your name, your saved addresses and your basket are removed, and nobody can sign in with
          this account again. Orders you have placed are kept, because a receipt belongs to the shop
          as much as to you, and anything you reviewed in public stays where it is without your name
          on it.
        </p>

        <Button variant="secondary" onClick={() => setAsking(true)}>
          Close my account
        </Button>

        {failure ? <Alert tone="danger">{failure}</Alert> : null}
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} aria-label="Close your account" className="space-y-4" noValidate>
      <Alert tone="danger">This cannot be undone. Your password confirms it is you.</Alert>

      <Field
        label="Current password"
        name="current_password"
        type="password"
        autoComplete="current-password"
        required
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        errors={fieldErrors.current_password}
      />

      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={pending}>
          {pending ? "Closing..." : "Close my account for good"}
        </Button>
        <Button variant="ghost" disabled={pending} onClick={() => setAsking(false)}>
          Keep my account
        </Button>
      </div>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </form>
  );
}
