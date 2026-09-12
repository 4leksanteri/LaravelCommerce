"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FieldFrame } from "@/components/ui/field";
import { Select } from "@/components/ui/select";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { PayoutCountry } from "@/lib/api/types";

/**
 * Opening the account the shop is paid into.
 *
 * **The countries are the API's list**, sent on the resource so there is one
 * home for where an account may be opened (ADR 0031). Their names are this
 * application's to write, and asking `Intl` for them beats a hand-kept table of
 * twenty-odd EU members that would go stale the first time the list changed.
 * The locale is fixed for the reason `formatMoney` fixes one: the server and
 * the browser have to render the same string.
 *
 * **The country is asked once and cannot be changed.** Stripe does not move an
 * account from one country to another, so the hint says so before it is chosen
 * rather than after.
 *
 * **Accepting Stripe's agreement is recorded here**, with the address and
 * browser it came from, because there is no Stripe-hosted page to do it on. The
 * box is required by the API, not by this form.
 */
const COUNTRY_NAMES = new Intl.DisplayNames(["en-GB"], { type: "region" });

export function PayoutOpenForm({ countries }: { countries: PayoutCountry[] }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [country, setCountry] = useState<string>("");
  const [accepted, setAccepted] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    await submit(async () => {
      await apiFetch("/seller/payout-account", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ country, accept_terms: accepted }),
      });

      router.refresh();
    });
  }

  return (
    <form onSubmit={onSubmit} aria-label="Open a payout account" className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <FieldFrame
        label="Where you live"
        hint="Asked once: Stripe cannot move an account from one country to another."
        errors={fieldErrors.country}
      >
        {(control) => (
          <Select
            {...control}
            name="country"
            required
            value={country}
            onChange={(event) => setCountry(event.target.value)}
          >
            <option value="" disabled>
              Choose a country
            </option>
            {countries.map((code) => (
              <option key={code} value={code}>
                {COUNTRY_NAMES.of(code) ?? code}
              </option>
            ))}
          </Select>
        )}
      </FieldFrame>

      <div className="space-y-1.5">
        <label className="text-muted-foreground flex items-start gap-2 text-sm">
          <input
            type="checkbox"
            name="accept_terms"
            checked={accepted}
            onChange={(event) => setAccepted(event.target.checked)}
            className="border-input text-primary focus-visible:ring-ring mt-0.5 size-4 rounded-sm focus-visible:ring-2 focus-visible:ring-offset-2"
          />
          I accept Stripe&apos;s Connected Account Agreement.
        </label>
        {fieldErrors.accept_terms?.map((message) => (
          <p key={message} className="text-destructive text-xs">
            {message}
          </p>
        ))}
        <p className="text-muted-foreground text-xs">
          The date, your address and your browser are recorded with it, because Stripe asks who
          accepted and when.
        </p>
      </div>

      <Button type="submit" disabled={pending}>
        {pending ? "Opening..." : "Open the account"}
      </Button>
    </form>
  );
}
