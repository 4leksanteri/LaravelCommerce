"use client";

import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldFrame } from "@/components/ui/field";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { Currency, Resource, Shop } from "@/lib/api/types";
import { loadFresh } from "@/lib/navigation";

/**
 * Applying to open a shop, or applying again after a rejection.
 *
 * **The currency is chosen once and never again** (ADR 0007), so there is no
 * default: somebody has to pick one on purpose. On a second application it is
 * already fixed, and it is shown rather than offered - the API keeps the first
 * one whatever is sent, and a menu suggesting otherwise would be a lie.
 *
 * **Success is a full page load into the shop.** Applying is what makes the
 * account a seller, and the header drawn above this form still says "Open a
 * shop". A client-side navigation would leave it saying so (`loadFresh`).
 *
 * The currencies' names are the frontend's to write, and `Record<Currency, ...>`
 * keeps the list honest: a currency the API adds is a type error here until it
 * has a name, rather than a shop nobody can open in it.
 */
const CURRENCY_NAMES: Record<Currency, string> = {
  EUR: "Euro (EUR)",
  USD: "US dollar (USD)",
  GBP: "Pound sterling (GBP)",
  SEK: "Swedish krona (SEK)",
  NOK: "Norwegian krone (NOK)",
  DKK: "Danish krone (DKK)",
};

type Props = {
  initial: { shopName: string; description: string; contactEmail: string };
  /** Set when applying again: the currency the first application fixed. */
  fixedCurrency?: Currency;
};

export function ShopApplicationForm({ initial, fixedCurrency }: Props) {
  const { pending, fieldErrors, failure, submit } = useApiSubmit();

  const [shopName, setShopName] = useState(initial.shopName);
  const [description, setDescription] = useState(initial.description);
  const [contactEmail, setContactEmail] = useState(initial.contactEmail);
  const [currency, setCurrency] = useState<Currency | "">(fixedCurrency ?? "");

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    await submit(async () => {
      await apiFetch<Resource<Shop>>("/seller/application", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          shop_name: shopName,
          description,
          contact_email: contactEmail,
          currency,
        }),
      });

      loadFresh("/seller");
    });
  }

  return (
    <form onSubmit={onSubmit} aria-label="Shop application" className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <Field
        label="Shop name"
        name="shop_name"
        required
        value={shopName}
        onChange={(event) => setShopName(event.target.value)}
        // Said here because it is not true of the name: the address is made
        // from it once and then kept, so saved links keep working.
        hint="It also becomes your shop's address in links, which stays the same if you rename the shop later."
        errors={fieldErrors.shop_name}
      />

      <FieldFrame
        label="About your shop"
        hint="Optional. What you sell, and anything a buyer should know."
        errors={fieldErrors.description}
      >
        {(control) => (
          <Textarea
            {...control}
            name="description"
            rows={4}
            value={description}
            onChange={(event) => setDescription(event.target.value)}
          />
        )}
      </FieldFrame>

      <Field
        label="Contact email"
        name="contact_email"
        type="email"
        autoComplete="email"
        required
        value={contactEmail}
        onChange={(event) => setContactEmail(event.target.value)}
        errors={fieldErrors.contact_email}
      />

      {fixedCurrency ? (
        <div className="space-y-1.5">
          <p className="text-sm font-medium">Currency</p>
          <p className="text-sm">{CURRENCY_NAMES[fixedCurrency]}</p>
          <p className="text-muted-foreground text-xs">Fixed by your first application.</p>
        </div>
      ) : (
        <FieldFrame
          label="Currency"
          hint="Everything your shop sells is priced in this, and it cannot be changed later."
          errors={fieldErrors.currency}
        >
          {(control) => (
            <Select
              {...control}
              name="currency"
              required
              value={currency}
              onChange={(event) => setCurrency(event.target.value as Currency | "")}
            >
              <option value="" disabled>
                Choose a currency
              </option>
              {(Object.keys(CURRENCY_NAMES) as Currency[]).map((code) => (
                <option key={code} value={code}>
                  {CURRENCY_NAMES[code]}
                </option>
              ))}
            </Select>
          )}
        </FieldFrame>
      )}

      <Button type="submit" size="block" disabled={pending}>
        {pending
          ? "Sending your application..."
          : fixedCurrency
            ? "Apply again"
            : "Send application"}
      </Button>
    </form>
  );
}
