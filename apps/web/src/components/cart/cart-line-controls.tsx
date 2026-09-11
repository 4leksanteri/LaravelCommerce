"use client";

import { useRouter } from "next/navigation";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { CartItem } from "@/lib/api/types";

/**
 * Changing how many, or taking a line out.
 *
 * **Every change is the API's to accept.** One more sends the new quantity and
 * the API decides: four in stock and a fifth asked for is a 409, shown in its
 * own words. Nothing here knows the stock, and the page is redrawn from the
 * API afterwards, so the totals are never worked out in the browser.
 *
 * **At one, "fewer" is "remove".** Removing is its own action rather than a
 * quantity of nought, so the control changes what it does rather than sending
 * a number the API would refuse.
 *
 * When there are fewer left than the line asks for, the API says how many
 * (`available_quantity`), and "change to that" is offered - its answer, not a
 * guess.
 *
 * A line that cannot be bought at all has nothing to adjust, so it has only
 * "remove". A 404 means the line was already gone - taken out in another tab -
 * which is the state the person wanted, so the page is simply redrawn.
 */
export function CartLineControls({ item }: { item: CartItem }) {
  const router = useRouter();
  const { pending, failure, submit } = useApiSubmit();

  async function change(request: () => Promise<unknown>) {
    await submit(async () => {
      try {
        await request();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent("/cart")}`);

          return;
        }

        if (error instanceof ApiError && error.status === 404) {
          router.refresh();

          return;
        }

        throw error;
      }

      router.refresh();
    });
  }

  const setQuantity = (quantity: number) =>
    change(() =>
      apiFetch(`/cart/items/${item.id}`, {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ quantity }),
      }),
    );

  const remove = () => change(() => apiFetch(`/cart/items/${item.id}`, { method: "DELETE" }));

  const adjustable =
    item.availability === "available" || item.availability === "insufficient_stock";

  return (
    <div className="space-y-2">
      <div className="flex flex-wrap items-center justify-end gap-2">
        {adjustable ? (
          <div className="border-border flex items-center rounded-md border">
            {item.quantity > 1 ? (
              <Stepper
                label={`One fewer ${item.product_name}`}
                disabled={pending}
                onClick={() => setQuantity(item.quantity - 1)}
              >
                -
              </Stepper>
            ) : (
              <Stepper label={`Remove ${item.product_name}`} disabled={pending} onClick={remove}>
                -
              </Stepper>
            )}
            <span className="min-w-8 px-1 text-center text-sm font-medium tabular-nums">
              <span className="sr-only">Quantity </span>
              {item.quantity}
            </span>
            <Stepper
              label={`One more ${item.product_name}`}
              disabled={pending}
              onClick={() => setQuantity(item.quantity + 1)}
            >
              +
            </Stepper>
          </div>
        ) : (
          <Button
            variant="ghost"
            size="sm"
            disabled={pending}
            onClick={remove}
            aria-label={`Remove ${item.product_name}`}
          >
            Remove
          </Button>
        )}

        {item.availability === "insufficient_stock" && item.available_quantity ? (
          <Button
            variant="secondary"
            size="sm"
            disabled={pending}
            onClick={() => setQuantity(item.available_quantity as number)}
          >
            Change to {item.available_quantity}
          </Button>
        ) : null}
      </div>

      {failure ? (
        <Alert tone="danger" className="text-xs">
          {failure}
        </Alert>
      ) : null}
    </div>
  );
}

function Stepper({
  label,
  disabled,
  onClick,
  children,
}: {
  label: string;
  disabled: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      aria-label={label}
      disabled={disabled}
      onClick={onClick}
      className="hover:bg-accent focus-visible:ring-ring h-8 w-8 text-base font-medium outline-none focus-visible:ring-2 disabled:opacity-60"
    >
      {children}
    </button>
  );
}
