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
import type { Currency, Product, Resource } from "@/lib/api/types";
import { parseMoney } from "@/lib/money";
import type { CategoryChoice } from "@/lib/sellers/categories";

/**
 * A new listing: what it is, and the first way to buy it.
 *
 * **One option here, and the rest on the listing's own page.** The API requires
 * at least one variant at creation, because a product with none has no price
 * and cannot be bought. It accepts many, and this form offers one: somebody
 * typing up a listing is describing a thing, and sizes and colours are easier
 * to get right against a saved listing than in a form that grows while it is
 * being filled in (ADR 0038).
 *
 * **The price is typed in the shop's currency and sent as minor units.**
 * `parseMoney` does that on the string, never through a float, and refuses
 * anything that is not a plain amount - which is the one refusal on this page
 * that is not the API's, because the API never sees an unreadable price.
 *
 * A category is optional here and needed before the listing can go on sale. The
 * API enforces that on publication (a 409), and the hint says so rather than
 * this form deciding it.
 */
export function ListingForm({
  categories,
  currency,
}: {
  categories: CategoryChoice[];
  currency: Currency;
}) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();

  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [categoryId, setCategoryId] = useState("");
  const [optionName, setOptionName] = useState("Default");
  const [price, setPrice] = useState("");
  const [stock, setStock] = useState("1");
  const [shipping, setShipping] = useState("0");
  const [priceProblem, setPriceProblem] = useState<string | null>(null);
  const [shippingProblem, setShippingProblem] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPriceProblem(null);
    setShippingProblem(null);

    const priceMinor = parseMoney(price, currency);

    if (priceMinor === null) {
      setPriceProblem(`Write the price as a plain amount in ${currency}, like 24.99.`);

      return;
    }

    // Read the same way the price is, on the string rather than through a
    // float (ADR 0057). It starts at 0, so a seller who posts free leaves it
    // alone and a valid amount is always what reaches the API.
    const shippingMinor = parseMoney(shipping, currency);

    if (shippingMinor === null) {
      setShippingProblem(
        `Write the postage as a plain amount in ${currency}, like 6.90. Nought is free delivery.`,
      );

      return;
    }

    await submit(async () => {
      const created = await apiFetch<Resource<Product>>("/seller/products", {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          name,
          description,
          category_id: categoryId === "" ? null : Number(categoryId),
          shipping_minor: shippingMinor,
          variants: [
            {
              name: optionName,
              price_minor: priceMinor,
              stock: Number(stock) || 0,
            },
          ],
        }),
      });

      router.push(`/seller/listings/${created.data.id}`);
      router.refresh();
    });
  }

  return (
    <form onSubmit={onSubmit} aria-label="New listing" className="space-y-4" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <Field
        label="Name"
        name="name"
        required
        value={name}
        onChange={(event) => setName(event.target.value)}
        hint="It also becomes the listing's address, which stays the same if you rename it later."
        errors={fieldErrors.name}
      />

      <FieldFrame
        label="Description"
        hint="Optional. Condition, what is included, anything a buyer should know."
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
        hint="Needed before the listing can go on sale, and you can choose it later."
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

      <fieldset className="border-border space-y-4 rounded-lg border p-4">
        <legend className="px-1 text-sm font-semibold">How it is sold</legend>

        <p className="text-muted-foreground text-sm">
          One way to buy it, with its own price and stock. You can add sizes or colours once the
          listing is saved.
        </p>

        <Field
          label="Option"
          name="variant_name"
          required
          value={optionName}
          onChange={(event) => setOptionName(event.target.value)}
          hint='What a buyer chooses. "Default" is fine when there is only one.'
          errors={fieldErrors["variants.0.name"]}
        />

        <Field
          label={`Price in ${currency}`}
          name="price"
          inputMode="decimal"
          required
          value={price}
          onChange={(event) => setPrice(event.target.value)}
          hint="Your shop's currency, chosen when you opened it."
          errors={
            priceProblem
              ? [priceProblem, ...(fieldErrors["variants.0.price_minor"] ?? [])]
              : fieldErrors["variants.0.price_minor"]
          }
        />

        <Field
          label="In stock"
          name="stock"
          type="number"
          min={0}
          required
          value={stock}
          onChange={(event) => setStock(event.target.value)}
          errors={fieldErrors["variants.0.stock"]}
        />
      </fieldset>

      {/*
       * Outside the "How it is sold" fieldset, because postage belongs to the
       * listing rather than to one option: every way of buying this thing goes
       * in the same parcel, and it is charged once per order however many of
       * this shop's things are in it (ADR 0057).
       */}
      <Field
        label={`Postage in ${currency}`}
        name="shipping_minor"
        inputMode="decimal"
        value={shipping}
        onChange={(event) => setShipping(event.target.value)}
        hint="Charged once per order, however many of your things a buyer takes. Leave it at 0 for free delivery."
        errors={shippingProblem ? [shippingProblem] : fieldErrors.shipping_minor}
      />

      <Button type="submit" disabled={pending}>
        {pending ? "Saving..." : "Save as a draft"}
      </Button>
    </form>
  );
}
