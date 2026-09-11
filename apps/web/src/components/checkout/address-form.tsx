"use client";

import { usePathname, useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Address, NewAddress, Resource } from "@/lib/api/types";

/**
 * Adding an address to the book, or changing one already in it.
 *
 * Checkout adds; the account's address book adds and changes (ADR 0034). Both
 * are this form, so an address is typed the same way wherever it is typed.
 *
 * The fields are the API's, and so is every rule about them. Country is a text
 * box asking for two letters rather than a list of countries, because a list
 * is a decision about where things can be sent, and the API has not made one:
 * it checks the shape of the code and nothing more (ADR 0021). Its refusal is
 * shown beside the field, in its words.
 *
 * Optional parts left empty are not sent, rather than sent as empty strings: a
 * region of "" is a region, and a region that is not there is null.
 *
 * Its own `<form>`, rendered beside the checkout's controls rather than inside
 * them, because forms cannot nest.
 */
type Draft = Record<keyof NewAddress, string>;

const EMPTY: Draft = {
  name: "",
  line1: "",
  line2: "",
  city: "",
  region: "",
  postal_code: "",
  country: "",
  phone: "",
};

function draftOf(address: Address): Draft {
  return {
    name: address.name,
    line1: address.line1,
    line2: address.line2 ?? "",
    city: address.city,
    region: address.region ?? "",
    postal_code: address.postal_code ?? "",
    country: address.country,
    phone: address.phone ?? "",
  };
}

export function AddressForm({
  address,
  onSaved,
  onCancel,
}: {
  /** The entry being changed. Without one, the form adds a new entry. */
  address?: Address;
  onSaved: (address: Address) => void;
  onCancel?: () => void;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [draft, setDraft] = useState<Draft>(address ? draftOf(address) : EMPTY);

  const set = (field: keyof Draft) => (value: string) =>
    setDraft((current) => ({ ...current, [field]: value }));

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    // A new entry leaves out what was left empty. A change sends it as null,
    // because the update is a PATCH, and a field it is not sent is a field it
    // leaves alone: clearing a phone number has to say so.
    const body = address
      ? Object.fromEntries(
          Object.entries(draft).map(([key, value]) => [key, value.trim() === "" ? null : value]),
        )
      : Object.fromEntries(Object.entries(draft).filter(([, value]) => value.trim() !== ""));

    await submit(async () => {
      try {
        const saved = await apiFetch<Resource<Address>>(
          address ? `/addresses/${address.id}` : "/addresses",
          {
            method: address ? "PATCH" : "POST",
            headers: { "content-type": "application/json" },
            body: JSON.stringify(body),
          },
        );

        onSaved(saved.data);
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent(pathname)}`);

          return;
        }

        throw error;
      }
    });
  }

  const field = (
    name: keyof Draft,
    label: string,
    autoComplete: string,
    extra: { required?: boolean; hint?: string; type?: string } = {},
  ) => (
    <Field
      label={label}
      name={name}
      type={extra.type ?? "text"}
      autoComplete={autoComplete}
      required={extra.required}
      hint={extra.hint}
      value={draft[name]}
      onChange={(event) => set(name)(event.target.value)}
      errors={fieldErrors[name]}
    />
  );

  return (
    <form
      onSubmit={onSubmit}
      noValidate
      aria-label={address ? "Edit address" : "New address"}
      className="bg-muted/40 border-border space-y-4 rounded-lg border p-4"
    >
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      {field("name", "Recipient", "name", { required: true, hint: "Who the parcel is for." })}
      {field("line1", "Address", "address-line1", { required: true })}
      {field("line2", "Address line 2 (optional)", "address-line2")}
      <div className="grid gap-4 sm:grid-cols-2">
        {field("postal_code", "Postcode (optional)", "postal-code")}
        {field("city", "City", "address-level2", { required: true })}
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        {field("region", "Region (optional)", "address-level1")}
        {field("country", "Country", "country", {
          required: true,
          hint: "Two letters, such as FI or GB.",
        })}
      </div>
      {field("phone", "Phone (optional)", "tel", {
        type: "tel",
        hint: "For the courier, if they need to reach you.",
      })}

      <div className="flex flex-wrap gap-2">
        <Button type="submit" disabled={pending}>
          {pending ? "Saving..." : "Save address"}
        </Button>
        {onCancel ? (
          <Button type="button" variant="ghost" onClick={onCancel} disabled={pending}>
            Cancel
          </Button>
        ) : null}
      </div>
    </form>
  );
}
