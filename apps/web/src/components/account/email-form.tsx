"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { AuthenticatedUser, Resource } from "@/lib/api/types";

/**
 * Moving the account to a new email address.
 *
 * **The current password is asked for** because the address is where a
 * password reset goes: changing it is how an account is taken, by whoever
 * finds it signed in on a shared computer.
 *
 * **The new address takes effect at once, unconfirmed** (ADR 0034), and the
 * API sends a link to it. The page is redrawn from the API afterwards, which is
 * what puts the new address in the sidebar and the "confirm your address"
 * prompt on the account's overview - both the API's answers, not this form's.
 */
export function EmailForm() {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [sentTo, setSentTo] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSentTo(null);

    await submit(async () => {
      const changed = await apiFetch<Resource<AuthenticatedUser>>("/account/email", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ email, current_password: password }),
      });

      setSentTo(changed.data.email);
      setEmail("");
      setPassword("");
      router.refresh();
    });
  }

  return (
    <form onSubmit={onSubmit} aria-label="Email address" className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}
      {sentTo ? (
        <Alert tone="positive">
          Changed. We sent a link to {sentTo} to confirm it. Until you follow it, checking out and
          opening a shop wait for it.
        </Alert>
      ) : null}

      <Field
        label="New email address"
        name="email"
        type="email"
        autoComplete="email"
        required
        value={email}
        onChange={(event) => setEmail(event.target.value)}
        hint="We send a link to it, to confirm you can read it."
        errors={fieldErrors.email}
      />

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

      <Button type="submit" disabled={pending}>
        {pending ? "Changing..." : "Change email address"}
      </Button>
    </form>
  );
}
