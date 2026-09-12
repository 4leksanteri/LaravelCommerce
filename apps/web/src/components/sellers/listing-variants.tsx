"use client";

import { useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field } from "@/components/ui/field";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { Product, ProductVariant } from "@/lib/api/types";
import { formatMoney, moneyInputValue, parseMoney } from "@/lib/money";

/**
 * The ways a listing can be bought: each option's price and how many are left.
 *
 * **A price lives on an option and nowhere else** (ADR 0009), so this is where
 * a seller changes what something costs. Each row saves on its own, and adding
 * one is its own form, because they are separate writes to the API and a single
 * Save over the lot would have to invent what to do when one of them failed.
 *
 * **The last option cannot be removed.** A listing with none has no price and
 * cannot be bought; the API refuses it with a 409 and its message is shown as
 * it was sent, rather than this component knowing the rule.
 *
 * Stock is a plain number here and is taken at checkout, which can move it
 * underneath this page - the figure shown is what the API last said.
 */
export function ListingVariants({ listing }: { listing: Product }) {
  const [adding, setAdding] = useState(false);

  return (
    <div className="space-y-3">
      <ul className="divide-border border-border divide-y rounded-lg border">
        {listing.variants.map((variant) => (
          <li key={variant.id} className="p-4">
            <VariantRow listing={listing} variant={variant} />
          </li>
        ))}
      </ul>

      {adding ? (
        <AddVariant listing={listing} onDone={() => setAdding(false)} />
      ) : (
        <Button variant="secondary" size="sm" onClick={() => setAdding(true)}>
          Add an option
        </Button>
      )}
    </div>
  );
}

/** One option, edited in place. */
function VariantRow({ listing, variant }: { listing: Product; variant: ProductVariant }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [editing, setEditing] = useState(false);
  const [name, setName] = useState(variant.name);
  const [price, setPrice] = useState(moneyInputValue(variant.price_minor, listing.currency));
  const [stock, setStock] = useState(String(variant.stock));
  const [priceProblem, setPriceProblem] = useState<string | null>(null);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPriceProblem(null);

    const priceMinor = parseMoney(price, listing.currency);

    if (priceMinor === null) {
      setPriceProblem(`Write the price as a plain amount in ${listing.currency}, like 24.99.`);

      return;
    }

    await submit(async () => {
      await apiFetch(`/seller/products/${listing.id}/variants/${variant.id}`, {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ name, price_minor: priceMinor, stock: Number(stock) || 0 }),
      });

      setEditing(false);
      router.refresh();
    });
  }

  async function remove() {
    // Removing the last one is refused with a 409, because a listing with no
    // options has no price (ADR 0009). `useApiSubmit` turns that into the
    // API's own sentence, so there is nothing to catch here.
    await submit(async () => {
      await apiFetch(`/seller/products/${listing.id}/variants/${variant.id}`, {
        method: "DELETE",
      });

      router.refresh();
    });
  }

  if (!editing) {
    return (
      <div className="space-y-2">
        <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
          <div className="min-w-0">
            <p className="font-medium">{variant.name}</p>
            <p className="text-muted-foreground text-sm">
              {formatMoney(variant.price_minor, listing.currency)},{" "}
              {variant.stock === 0 ? "none left" : `${variant.stock} in stock`}
            </p>
          </div>
          <div className="flex gap-2">
            <Button variant="secondary" size="sm" onClick={() => setEditing(true)}>
              Change
            </Button>
            <Button variant="ghost" size="sm" disabled={pending} onClick={remove}>
              Remove
            </Button>
          </div>
        </div>

        {failure ? <Alert tone="danger">{failure}</Alert> : null}
      </div>
    );
  }

  return (
    <form onSubmit={save} aria-label={`Change ${variant.name}`} className="space-y-3" noValidate>
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <Field
        label="Option"
        name="name"
        required
        value={name}
        onChange={(event) => setName(event.target.value)}
        errors={fieldErrors.name}
      />

      <Field
        label={`Price in ${listing.currency}`}
        name="price"
        inputMode="decimal"
        required
        value={price}
        onChange={(event) => setPrice(event.target.value)}
        errors={problems(priceProblem, fieldErrors.price_minor)}
      />

      <Field
        label="In stock"
        name="stock"
        type="number"
        min={0}
        required
        value={stock}
        onChange={(event) => setStock(event.target.value)}
        errors={fieldErrors.stock}
      />

      <div className="flex flex-wrap gap-2">
        <Button type="submit" size="sm" disabled={pending}>
          {pending ? "Saving..." : "Save"}
        </Button>
        <Button variant="ghost" size="sm" disabled={pending} onClick={() => setEditing(false)}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

/** Another way to buy the same thing: a size, a colour, a strap. */
function AddVariant({ listing, onDone }: { listing: Product; onDone: () => void }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [name, setName] = useState("");
  const [price, setPrice] = useState("");
  const [stock, setStock] = useState("1");
  const [priceProblem, setPriceProblem] = useState<string | null>(null);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPriceProblem(null);

    const priceMinor = parseMoney(price, listing.currency);

    if (priceMinor === null) {
      setPriceProblem(`Write the price as a plain amount in ${listing.currency}, like 24.99.`);

      return;
    }

    await submit(async () => {
      await apiFetch(`/seller/products/${listing.id}/variants`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ name, price_minor: priceMinor, stock: Number(stock) || 0 }),
      });

      onDone();
      router.refresh();
    });
  }

  return (
    <form
      onSubmit={onSubmit}
      aria-label="Add an option"
      className="border-border bg-muted space-y-3 rounded-lg border p-4"
      noValidate
    >
      {failure ? <Alert tone="danger">{failure}</Alert> : null}

      <Field
        label="Option"
        name="name"
        required
        autoFocus
        value={name}
        onChange={(event) => setName(event.target.value)}
        hint="What a buyer chooses: a size, a colour, a strap."
        errors={fieldErrors.name}
      />

      <Field
        label={`Price in ${listing.currency}`}
        name="price"
        inputMode="decimal"
        required
        value={price}
        onChange={(event) => setPrice(event.target.value)}
        errors={problems(priceProblem, fieldErrors.price_minor)}
      />

      <Field
        label="In stock"
        name="stock"
        type="number"
        min={0}
        required
        value={stock}
        onChange={(event) => setStock(event.target.value)}
        errors={fieldErrors.stock}
      />

      <div className="flex flex-wrap gap-2">
        <Button type="submit" size="sm" disabled={pending}>
          {pending ? "Adding..." : "Add the option"}
        </Button>
        <Button variant="ghost" size="sm" disabled={pending} onClick={onDone}>
          Cancel
        </Button>
      </div>
    </form>
  );
}

/**
 * This page's own refusal first, then the API's. The first is for a price that
 * could not be read, which the API never sees.
 */
function problems(local: string | null, fromApi: string[] | undefined): string[] | undefined {
  if (!local) {
    return fromApi;
  }

  return [local, ...(fromApi ?? [])];
}
