"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldFrame } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { Resource, Shop } from "@/lib/api/types";

/**
 * Changing a shop's name, what it says about itself, and its contact address.
 *
 * All three are sent every time. The endpoint is a PATCH and would take any one
 * of them, but a form that sends what is on screen is a form whose result is
 * what was on screen.
 *
 * Saving redraws the page from the API, which is also what puts a new name in
 * the sidebar: `router.refresh()` draws layouts again, where a navigation would
 * not (ADR 0030).
 */
export function ShopDetailsForm({ shop }: { shop: Shop }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();

  const [shopName, setShopName] = useState(shop.shop_name);
  const [description, setDescription] = useState(shop.description ?? "");
  const [contactEmail, setContactEmail] = useState(shop.contact_email);
  const [saved, setSaved] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(false);

    await submit(async () => {
      await apiFetch<Resource<Shop>>("/seller", {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          shop_name: shopName,
          description,
          contact_email: contactEmail,
        }),
      });

      setSaved(true);
      router.refresh();
    });
  }

  return (
    <form onSubmit={onSubmit} aria-label="Shop details" className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}
      {saved ? <Alert tone="positive">Saved.</Alert> : null}

      <Field
        label="Shop name"
        name="shop_name"
        required
        value={shopName}
        onChange={(event) => setShopName(event.target.value)}
        errors={fieldErrors.shop_name}
      />

      <FieldFrame
        label="About your shop"
        hint="What you sell, and anything a buyer should know."
        errors={fieldErrors.description}
      >
        {(control) => (
          <Textarea
            {...control}
            name="description"
            rows={5}
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

      <Button type="submit" disabled={pending}>
        {pending ? "Saving..." : "Save"}
      </Button>
    </form>
  );
}
