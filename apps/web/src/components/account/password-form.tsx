"use client";

import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";

/**
 * Replacing the account's password.
 *
 * The API signs out every other session the account has, and keeps this one
 * going under a new id (ADR 0034). That new id arrives as a cookie on the
 * response, through the proxy, so nothing here has to do anything for the page
 * to go on working - and the CSRF token is read afresh on the next write, as it
 * always is (`apiFetch`).
 *
 * The fields are emptied after a change. Passwords left sitting in a form are
 * passwords somebody else can read off the screen.
 */
export function PasswordForm() {
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [current, setCurrent] = useState("");
  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [changed, setChanged] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setChanged(false);

    await submit(async () => {
      await apiFetch<void>("/account/password", {
        method: "PUT",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          current_password: current,
          password,
          password_confirmation: confirmation,
        }),
      });

      setCurrent("");
      setPassword("");
      setConfirmation("");
      setChanged(true);
    });
  }

  return (
    <form onSubmit={onSubmit} aria-label="Password" className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}
      {changed ? (
        <Alert tone="positive">
          Changed. Anywhere else this account was signed in has been signed out; you are still
          signed in here.
        </Alert>
      ) : null}

      <Field
        label="Current password"
        name="current_password"
        type="password"
        autoComplete="current-password"
        required
        value={current}
        onChange={(event) => setCurrent(event.target.value)}
        errors={fieldErrors.current_password}
      />

      <Field
        label="New password"
        name="password"
        type="password"
        autoComplete="new-password"
        required
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        // The API's rule, `Password::defaults()`, as the registration form says
        // it; the sentence moves when that does.
        hint="At least 12 characters."
        errors={fieldErrors.password}
      />

      <Field
        label="Confirm new password"
        name="password_confirmation"
        type="password"
        autoComplete="new-password"
        required
        value={confirmation}
        onChange={(event) => setConfirmation(event.target.value)}
        errors={fieldErrors.password_confirmation}
      />

      <Button type="submit" disabled={pending}>
        {pending ? "Changing..." : "Change password"}
      </Button>
    </form>
  );
}
