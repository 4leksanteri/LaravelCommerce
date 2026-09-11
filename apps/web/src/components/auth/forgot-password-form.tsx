"use client";

import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { apiFetch } from "@/lib/api/client";
import { useApiSubmit } from "@/hooks/use-api-submit";

/**
 * Asking for a reset link.
 *
 * **The same answer for an address with an account and one without**, which is
 * the whole point of the endpoint and the one thing this screen must not undo.
 * The API has no `exists:users,email` rule for exactly this reason (ADR 0002):
 * a form that said "no account with that address" would be a way to find out
 * who has one, one address at a time.
 *
 * So success is rendered from the request having been accepted, never from
 * anything about who the address belongs to - and the wording says a link has
 * been sent *if* the address is known.
 */
export function ForgotPasswordForm() {
  const { pending, fieldErrors, failure, submit } = useApiSubmit();

  const [email, setEmail] = useState("");
  const [sent, setSent] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    await submit(async () => {
      await apiFetch<{ message: string }>("/auth/password/forgot", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ email }),
      });

      setSent(true);
    });
  }

  if (sent) {
    return (
      <Alert tone="positive">
        If there is an account for <span className="font-medium">{email}</span>, a link to reset the
        password is on its way. It is valid for one hour.
      </Alert>
    );
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <Field
        label="Email"
        name="email"
        type="email"
        autoComplete="email"
        autoFocus
        required
        value={email}
        onChange={(event) => setEmail(event.target.value)}
        errors={fieldErrors.email}
      />

      <Button type="submit" size="block" disabled={pending}>
        {pending ? "Sending..." : "Email me a link"}
      </Button>
    </form>
  );
}
