"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldFrame } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { PayoutField } from "@/lib/api/types";
import { collectableFields, PAYOUT_FIELDS } from "@/lib/sellers/payouts";

/**
 * What Stripe is still asking for, as a form.
 *
 * **The API decides which fields appear.** `due` is Stripe's outstanding
 * requirements translated into this API's words (ADR 0031), and it changes by
 * country and over time. Nothing here has a list of what a Finn or an Italian
 * must provide: the form is whatever arrived, in the order it arrived, and a
 * field this application cannot draw is named on the page rather than hidden.
 *
 * **Only what is shown is sent.** Every rule on the endpoint is `sometimes`,
 * so a request carries the fields that were asked for and no others - which is
 * what lets the same endpoint serve a first submission and a single correction
 * months later.
 *
 * An address is five inputs and one value, and a date of birth is a date
 * input, because those are the shapes the API takes.
 */
type Address = { line1: string; line2: string; city: string; postal_code: string; state: string };

const EMPTY_ADDRESS: Address = { line1: "", line2: "", city: "", postal_code: "", state: "" };

export function PayoutDetailsForm({ due }: { due: PayoutField[] }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [values, setValues] = useState<Partial<Record<PayoutField, string>>>({});
  const [address, setAddress] = useState<Address>(EMPTY_ADDRESS);
  const [acceptedTerms, setAcceptedTerms] = useState(false);
  const [saved, setSaved] = useState(false);

  const fields = collectableFields(due);
  const termsDue = due.includes("terms");

  function set(field: PayoutField, value: string) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(false);

    const payload: Record<string, unknown> = {};

    for (const field of fields) {
      payload[field] = field === "address" ? address : (values[field] ?? "");
    }

    if (termsDue) {
      payload.terms = acceptedTerms;
    }

    await submit(async () => {
      await apiFetch("/seller/payout-account", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify(payload),
      });

      setSaved(true);
      router.refresh();
    });
  }

  return (
    <form onSubmit={onSubmit} aria-label="Payout details" className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}
      {saved ? <Alert tone="positive">Sent to Stripe.</Alert> : null}

      {fields.map((field) => {
        const copy = PAYOUT_FIELDS[field];

        if (field === "address") {
          return (
            <fieldset key={field} className="border-border space-y-3 rounded-lg border p-4">
              <legend className="px-1 text-sm font-semibold">{copy.label}</legend>
              {copy.hint ? <p className="text-muted-foreground text-sm">{copy.hint}</p> : null}

              <Field
                label="Street and number"
                name="address.line1"
                required
                value={address.line1}
                onChange={(event) => setAddress({ ...address, line1: event.target.value })}
                errors={fieldErrors["address.line1"]}
              />
              <Field
                label="Flat, floor or care of"
                name="address.line2"
                value={address.line2}
                onChange={(event) => setAddress({ ...address, line2: event.target.value })}
                errors={fieldErrors["address.line2"]}
              />
              <Field
                label="City"
                name="address.city"
                required
                value={address.city}
                onChange={(event) => setAddress({ ...address, city: event.target.value })}
                errors={fieldErrors["address.city"]}
              />
              <Field
                label="Postcode"
                name="address.postal_code"
                value={address.postal_code}
                onChange={(event) => setAddress({ ...address, postal_code: event.target.value })}
                errors={fieldErrors["address.postal_code"]}
              />
              <Field
                label="Region"
                name="address.state"
                value={address.state}
                onChange={(event) => setAddress({ ...address, state: event.target.value })}
                errors={fieldErrors["address.state"]}
              />
            </fieldset>
          );
        }

        return (
          <FieldFrame key={field} label={copy.label} hint={copy.hint} errors={fieldErrors[field]}>
            {(control) => (
              <Input
                {...control}
                name={field}
                type={inputType(field)}
                required
                autoComplete={autoComplete(field)}
                value={values[field] ?? ""}
                onChange={(event) => set(field, event.target.value)}
              />
            )}
          </FieldFrame>
        );
      })}

      {termsDue ? (
        <div className="space-y-1.5">
          <label className="text-muted-foreground flex items-start gap-2 text-sm">
            <input
              type="checkbox"
              name="terms"
              checked={acceptedTerms}
              onChange={(event) => setAcceptedTerms(event.target.checked)}
              className="border-input text-primary focus-visible:ring-ring mt-0.5 size-4 rounded-sm focus-visible:ring-2 focus-visible:ring-offset-2"
            />
            I accept Stripe&apos;s Connected Account Agreement, which has changed.
          </label>
          {fieldErrors.terms?.map((message) => (
            <p key={message} className="text-destructive text-xs">
              {message}
            </p>
          ))}
        </div>
      ) : null}

      <Button type="submit" disabled={pending}>
        {pending ? "Sending to Stripe..." : "Send to Stripe"}
      </Button>
    </form>
  );
}

function inputType(field: PayoutField): string {
  switch (field) {
    case "email":
      return "email";
    case "phone":
      return "tel";
    case "date_of_birth":
      return "date";
    default:
      return "text";
  }
}

function autoComplete(field: PayoutField): string | undefined {
  switch (field) {
    case "first_name":
      return "given-name";
    case "last_name":
      return "family-name";
    case "email":
      return "email";
    case "phone":
      return "tel";
    case "date_of_birth":
      return "bday";
    default:
      return undefined;
  }
}
