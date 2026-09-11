"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { TextLink } from "@/components/ui/text-link";
import { apiFetch } from "@/lib/api/client";
import type { AuthenticatedUser, Resource } from "@/lib/api/types";
import { useApiSubmit } from "@/hooks/use-api-submit";

/**
 * Signing in.
 *
 * The session is a cookie Laravel sets, travelling back through this
 * application's proxy, so there is nothing to store when this succeeds. What
 * looks like a missing step - no token kept, no user written to state - is the
 * design (ADR 0002, ADR 0003).
 *
 * `router.refresh()` before navigating, because every Server Component on the
 * next page asks the API who is signed in, and the cached render of the page
 * being left was produced for somebody who was not.
 */
export function LoginForm({ redirectTo }: { redirectTo: string }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();

  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [remember, setRemember] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    await submit(async () => {
      await apiFetch<Resource<AuthenticatedUser>>("/auth/login", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ email, password, remember }),
      });

      router.refresh();
      router.push(redirectTo);
    });
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

      <div className="space-y-1.5">
        <Field
          label="Password"
          name="password"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(event) => setPassword(event.target.value)}
          errors={fieldErrors.password}
        />

        <p className="text-right">
          <TextLink href="/forgot-password">Forgotten your password?</TextLink>
        </p>
      </div>

      <label className="text-muted-foreground flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          name="remember"
          checked={remember}
          onChange={(event) => setRemember(event.target.checked)}
          className="border-input text-primary focus-visible:ring-ring size-4 rounded-sm focus-visible:ring-2 focus-visible:ring-offset-2"
        />
        Keep me signed in
      </label>

      <Button type="submit" size="block" disabled={pending}>
        {pending ? "Signing in..." : "Sign in"}
      </Button>
    </form>
  );
}
