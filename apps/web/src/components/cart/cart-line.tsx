import Link from "next/link";

import { CartLineControls } from "@/components/cart/cart-line-controls";
import type { CartItem, Currency } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";

/**
 * One line of the cart: what it is, what it costs now, and whether it can still
 * be bought.
 *
 * **Every figure here is the API's.** The unit price is read from the variant
 * each time the cart is shown (ADR 0010), and `line_total_minor` is the API's
 * own arithmetic - this does not multiply price by quantity, because a figure
 * worked out here is one that can disagree with what checkout charges.
 *
 * `added_price_minor` is shown only to say the price moved, and only as what it
 * was. It is never totalled and never compared here: the API has already said
 * `price_changed`, and "it went up" would be a second answer to that.
 *
 * The listing is linked while there is one. `product_slug` is null once a
 * listing is gone, and the line then says what it was without pretending there
 * is somewhere to go.
 */
export function CartLine({
  item,
  shopSlug,
  currency,
}: {
  item: CartItem;
  shopSlug: string;
  currency: Currency;
}) {
  const href = item.product_slug ? `/shops/${shopSlug}/products/${item.product_slug}` : null;

  return (
    <li className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between">
      <div className="min-w-0 space-y-1">
        <p className="font-medium">
          {href ? (
            <Link href={href} className="hover:text-primary hover:underline">
              {item.product_name}
            </Link>
          ) : (
            item.product_name
          )}
        </p>
        <p className="text-muted-foreground text-sm">
          {item.variant_name}, {formatMoney(item.unit_price_minor, currency)} each
        </p>

        {item.price_changed ? (
          <p className="text-muted-foreground text-xs">
            The price has changed since you added it. It was{" "}
            {formatMoney(item.added_price_minor, currency)}.
          </p>
        ) : null}

        <Availability item={item} />
      </div>

      <div className="flex shrink-0 items-center justify-between gap-4 sm:flex-col sm:items-end">
        <p className="font-semibold tabular-nums">{formatMoney(item.line_total_minor, currency)}</p>
        <CartLineControls item={item} />
      </div>
    </li>
  );
}

/**
 * The API's answer to "can this line be bought", in words. Exhaustive on
 * purpose: a fifth availability added to the API fails the type check here
 * rather than rendering as though it were fine.
 */
function Availability({ item }: { item: CartItem }) {
  switch (item.availability) {
    case "available":
      return null;
    case "insufficient_stock":
      return (
        <p className="text-destructive text-sm">
          Only {item.available_quantity ?? 0} left - fewer than you asked for.
        </p>
      );
    case "out_of_stock":
      return <p className="text-destructive text-sm">Out of stock. The shop has none left.</p>;
    case "no_longer_for_sale":
      return <p className="text-destructive text-sm">No longer for sale.</p>;
    default: {
      const unhandled: never = item.availability;

      return unhandled;
    }
  }
}
