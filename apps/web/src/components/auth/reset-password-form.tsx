"use client";

import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { TextLink } from "@/components/ui/text-link";
import { apiFetch } from "@/lib/api/client";
import { useApiSubmit } from "@/hooks/use-api-submit";

/**
 * Choosing a new password, from the link in the email.
 *
 * `token` and `email` are carried in the URL and submitted back untouched. The
 * broker hashes the token and compares it to what it stored, so this
 * application never learns anything from it and must not try to interpret it.
 *
 * **A refusal about `email` is rendered as a message about the link**, not
 * under a field. The address is not editable here - it came from the URL - so a
 * message beside it would be advice nobody can act on. What the API is actually
 * saying with "this token is invalid" is that the link has expired or has been
 * used, and the useful next step is to ask for another one.
 *
 * Resetting does not sign anybody in: the API answers 204 and the session is
 * untouched, so this ends by sending them to sign in with the password they
 * have just chosen.
 */
export function ResetPasswordForm({ token, email }: { token: string; email: string }) {
  const { pending, fieldErrors, failure, submit } = useApiSubmit();

  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [done, setDone] = useState(false);

  const linkRefused = fieldErrors.email?.join(" ") ?? null;

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    await submit(async () => {
      await apiFetch<void>("/auth/password/reset", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          token,
          email,
          password,
          password_confirmation: passwordConfirmation,
        }),
      });

      setDone(true);
    });
  }

  if (done) {
    return (
      <div className="space-y-4">
        <Alert tone="positive">
          Your password has been changed. Any other device that was kept signed in has been signed
          out.
        </Alert>

        <TextLink href="/login">Sign in</TextLink>
      </div>
    );
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      {linkRefused ? (
        <Alert tone="danger">
          {linkRefused} <TextLink href="/forgot-password">Ask for a new link.</TextLink>
        </Alert>
      ) : null}

      <div className="space-y-1.5">
        <span className="text-foreground block text-sm font-medium">Email</span>
        <p className="border-border bg-muted text-muted-foreground rounded-md border px-3 py-2 text-sm">
          {email}
        </p>
      </div>

      <Field
        label="New password"
        name="password"
        type="password"
        autoComplete="new-password"
        autoFocus
        required
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        hint="At least 12 characters."
        errors={fieldErrors.password}
      />

      <Field
        label="Confirm new password"
        name="password_confirmation"
        type="password"
        autoComplete="new-password"
        required
        value={passwordConfirmation}
        onChange={(event) => setPasswordConfirmation(event.target.value)}
        errors={fieldErrors.password_confirmation}
      />

      <Button type="submit" size="block" disabled={pending}>
        {pending ? "Saving..." : "Change password"}
      </Button>
    </form>
  );
}
