"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { apiFetch } from "@/lib/api/client";
import type { AuthenticatedUser, Resource } from "@/lib/api/types";
import { useApiSubmit } from "@/hooks/use-api-submit";

/**
 * Opening an account.
 *
 * Registration signs the person in and sends a verification email, so this
 * lands on a page telling them to go and read it rather than on a sign-in form
 * they no longer need.
 *
 * **Registration answers 422 for an address that is already taken**, and that
 * is a deliberate exception to how the rest of authentication behaves - login
 * and password reset both refuse to say whether an address is known (ADR 0002).
 * A signup form cannot avoid it: it has to say why it will not proceed. Nothing
 * here has to do anything about that; it renders the message the API sent.
 */
export function RegisterForm() {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();

  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    await submit(async () => {
      await apiFetch<Resource<AuthenticatedUser>>("/auth/register", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          name,
          email,
          password,
          password_confirmation: passwordConfirmation,
        }),
      });

      router.refresh();
      router.push("/verify-email/sent");
    });
  }

  return (
    <form onSubmit={onSubmit} className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <Field
        label="Name"
        name="name"
        autoComplete="name"
        autoFocus
        required
        value={name}
        onChange={(event) => setName(event.target.value)}
        errors={fieldErrors.name}
      />

      <Field
        label="Email"
        name="email"
        type="email"
        autoComplete="email"
        required
        value={email}
        onChange={(event) => setEmail(event.target.value)}
        errors={fieldErrors.email}
      />

      <Field
        label="Password"
        name="password"
        type="password"
        autoComplete="new-password"
        required
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        // Stated rather than left to be discovered by being refused. The rule
        // is the API's - `Password::defaults()` is `min(12)`, and checked
        // against known breaches in production - and this sentence has to move
        // when that does.
        hint="At least 12 characters."
        errors={fieldErrors.password}
      />

      <Field
        label="Confirm password"
        name="password_confirmation"
        type="password"
        autoComplete="new-password"
        required
        value={passwordConfirmation}
        onChange={(event) => setPasswordConfirmation(event.target.value)}
        errors={fieldErrors.password_confirmation}
      />

      <Button type="submit" size="block" disabled={pending}>
        {pending ? "Creating your account..." : "Create account"}
      </Button>
    </form>
  );
}
