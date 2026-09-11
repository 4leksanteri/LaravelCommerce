"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button, buttonStyles } from "@/components/ui/button";
import { TextLink } from "@/components/ui/text-link";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { Cart, Currency, PublicProduct, Resource } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";
import { cn } from "@/lib/utils";

type Variant = PublicProduct["variants"][number];

/**
 * Choosing an option, and putting it in the basket.
 *
 * **The price shown is the chosen option's own**, straight from the API. A
 * listing with two sizes has two prices (ADR 0009), and the figure beside the
 * button must be the one the button adds.
 *
 * **One at a time.** There is no quantity field: most of what is sold here is
 * the only one of its kind, and a spinner would need its own copy of the
 * API's quantity rules to know where to stop. How many is the cart page's
 * question, where the API already answers it per line.
 *
 * **Whether somebody is signed in is the server's answer, passed in.** A
 * signed-out shopper sees a link to sign in and come back here instead of a
 * button that would fail - the page knew before it was drawn, so the page says
 * so. `signInHref` is that answer. A session that ends between drawing the page
 * and pressing the button is a 401, which is sent to the same place.
 *
 * A 409 - sold out since the page loaded, fewer left than asked for, no longer
 * for sale - is shown in the API's own words, which say what changed.
 */
export function AddToCart({
  variants,
  currency,
  signInHref,
}: {
  variants: Variant[];
  currency: Currency;
  signInHref: string | null;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();

  const initial = variants.find((variant) => variant.in_stock) ?? variants[0];
  const [chosenId, setChosenId] = useState<number | undefined>(initial?.id);
  const [added, setAdded] = useState(false);

  const chosen = variants.find((variant) => variant.id === chosenId) ?? null;

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!chosen) {
      return;
    }

    setAdded(false);

    await submit(async () => {
      try {
        await apiFetch<Resource<Cart>>("/cart/items", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ variant_id: chosen.id }),
        });
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent(pathname)}`);

          return;
        }

        throw error;
      }

      setAdded(true);

      // The header's count is a Server Component's answer to "what is in the
      // basket". Refreshing asks again; nothing here keeps a count of its own.
      router.refresh();
    });
  }

  const refusal = failure ?? fieldErrors.variant_id?.join(" ") ?? null;

  return (
    <form onSubmit={onSubmit} className="space-y-4">
      {chosen ? (
        <p className="text-3xl font-bold tracking-tight tabular-nums">
          {formatMoney(chosen.price_minor, currency)}
        </p>
      ) : null}

      {variants.length > 1 ? (
        <fieldset className="space-y-2">
          <legend className="text-sm font-medium">Choose one</legend>
          <div className="flex flex-wrap gap-2">
            {variants.map((variant) => (
              <label
                key={variant.id}
                className={cn(
                  "bg-card border-border flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-sm",
                  "has-checked:border-primary has-checked:bg-accent",
                  "has-focus-visible:ring-ring has-focus-visible:ring-2 has-focus-visible:ring-offset-2",
                  "has-disabled:cursor-not-allowed has-disabled:opacity-60",
                )}
              >
                <input
                  type="radio"
                  name="variant"
                  value={variant.id}
                  checked={variant.id === chosenId}
                  disabled={!variant.in_stock}
                  onChange={() => {
                    setChosenId(variant.id);
                    setAdded(false);
                  }}
                  className="accent-primary"
                />
                <span className="font-medium">{variant.name}</span>
                <span className="text-muted-foreground tabular-nums">
                  {formatMoney(variant.price_minor, currency)}
                </span>
                {variant.in_stock ? null : <span className="text-muted-foreground">sold out</span>}
              </label>
            ))}
          </div>
        </fieldset>
      ) : null}

      {refusal ? <Alert tone="danger">{refusal}</Alert> : null}

      {added ? (
        <Alert tone="positive">
          Added to your cart. <TextLink href="/cart">View your cart</TextLink>
        </Alert>
      ) : null}

      {signInHref ? (
        <Link href={signInHref} className={buttonStyles({ size: "block" })}>
          Sign in to add to your cart
        </Link>
      ) : (
        <Button type="submit" size="block" disabled={pending || !chosen?.in_stock}>
          {pending ? "Adding..." : "Add to cart"}
        </Button>
      )}

      {/*
       * Amber, because the design notes reserve it for money being held, and
       * every step of the flow should say plainly who has the money. This one
       * is a promise the API keeps (ADR 0014).
       */}
      <p className="text-muted-foreground flex items-center gap-2 text-xs">
        <span aria-hidden="true" className="bg-caution size-2 shrink-0 rounded-full" />
        Your payment is held until you confirm the parcel arrived.
      </p>
    </form>
  );
}
