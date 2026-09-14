"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldFrame } from "@/components/ui/field";
import { Select } from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { Product, Resource } from "@/lib/api/types";
import { moneyInputValue, parseMoney } from "@/lib/money";
import type { CategoryChoice } from "@/lib/sellers/categories";

/**
 * What the listing says about itself: its name, its description and the
 * category shoppers find it under.
 *
 * Separate from the options and the photographs, because each is saved on its
 * own. A listing's page is a page of things that are edited separately, rather
 * than one form with a single Save that has to be pressed after touching
 * anything (ADR 0038).
 *
 * **The address does not move when the name changes.** The slug is made once
 * and kept, so saved links keep working, and the hint says so where somebody
 * is about to rename something.
 */
export function ListingDetailsForm({
  listing,
  categories,
}: {
  listing: Product;
  categories: CategoryChoice[];
}) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();

  const [name, setName] = useState(listing.name);
  const [description, setDescription] = useState(listing.description ?? "");
  const [categoryId, setCategoryId] = useState(listing.category ? String(listing.category.id) : "");
  const [shipping, setShipping] = useState(
    moneyInputValue(listing.shipping_minor, listing.currency),
  );
  const [shippingProblem, setShippingProblem] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaved(false);
    setShippingProblem(null);

    /*
     * Typed as an amount and sent as minor units, the way a variant's price is
     * (ADR 0038). `parseMoney` works on the string rather than multiplying a
     * float, and refuses anything that is not a plain amount - so what reaches
     * the API is exactly what was read, or nothing at all.
     */
    const shippingMinor = parseMoney(shipping, listing.currency);

    if (shippingMinor === null) {
      setShippingProblem(
        `Write the postage as a plain amount in ${listing.currency}, like 6.90. Nought is free delivery.`,
      );

      return;
    }

    await submit(async () => {
      await apiFetch<Resource<Product>>(`/seller/products/${listing.id}`, {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          name,
          description,
          category_id: categoryId === "" ? null : Number(categoryId),
          shipping_minor: shippingMinor,
        }),
      });

      setSaved(true);
      router.refresh();
    });
  }

  return (
    <form onSubmit={onSubmit} aria-label="Listing details" className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}
      {saved ? <Alert tone="positive">Saved.</Alert> : null}

      <Field
        label="Name"
        name="name"
        required
        value={name}
        onChange={(event) => setName(event.target.value)}
        hint="Renaming it does not move its address, so links people saved keep working."
        errors={fieldErrors.name}
      />

      <FieldFrame
        label="Description"
        hint="Condition, what is included, anything a buyer should know."
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

      <FieldFrame
        label="Category"
        hint="Needed before the listing can go on sale."
        errors={fieldErrors.category_id}
      >
        {(control) => (
          <Select
            {...control}
            name="category_id"
            value={categoryId}
            onChange={(event) => setCategoryId(event.target.value)}
          >
            <option value="">No category yet</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {"\u00a0".repeat(category.depth * 4)}
                {category.name}
              </option>
            ))}
          </Select>
        )}
      </FieldFrame>

      {/*
       * What it costs to post (ADR 0057).
       *
       * Charged once per order rather than once per item, because one order
       * with this shop is one parcel - so this is what the box costs to send,
       * not what each thing in it costs. Nought is free delivery, which is an
       * ordinary answer rather than a missing one.
       */}
      <Field
        label={`Postage in ${listing.currency}`}
        name="shipping_minor"
        inputMode="decimal"
        value={shipping}
        onChange={(event) => setShipping(event.target.value)}
        hint="Charged once per order, however many of your things are in it. Leave it at 0 for free delivery."
        errors={shippingProblem ? [shippingProblem] : fieldErrors.shipping_minor}
      />

      <Button type="submit" disabled={pending}>
        {pending ? "Saving..." : "Save"}
      </Button>
    </form>
  );
}
