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
 * Changing the account's name. Nothing to prove, so no password: a name is
 * what shops see on an order, not a way into the account.
 *
 * Saving redraws the page, which is what puts the new name in the sidebar
 * beside it (`router.refresh()` draws layouts again, ADR 0030).
 */
export function NameForm({ name }: { name: string }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [value, setValue] = useState(name);
  const [saved, setSaved] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(false);

    await submit(async () => {
      await apiFetch<Resource<AuthenticatedUser>>("/account", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ name: value }),
      });

      setSaved(true);
      router.refresh();
    });
  }

  return (
    <form onSubmit={onSubmit} aria-label="Your name" className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}
      {saved ? <Alert tone="positive">Saved.</Alert> : null}

      <Field
        label="Name"
        name="name"
        autoComplete="name"
        required
        value={value}
        onChange={(event) => setValue(event.target.value)}
        hint="Shops see it on your orders."
        errors={fieldErrors.name}
      />

      <Button type="submit" disabled={pending}>
        {pending ? "Saving..." : "Save name"}
      </Button>
    </form>
  );
}
