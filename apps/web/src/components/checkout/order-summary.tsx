import type { Cart } from "@/lib/api/types";
import { formatMoney } from "@/lib/money";

/**
 * What is about to be ordered, one group per shop - read only.
 *
 * Each group becomes its own order, in its shop's currency, and the page says so
 * rather than showing a total across them that is not a number (ADR 0004). The
 * figures are the cart's, read on the server just now; checkout reads them
 * again under lock when the button is pressed, and that is what is charged.
 */
export function OrderSummary({ cart }: { cart: Cart }) {
  return (
    <section aria-labelledby="summary-heading" className="space-y-3">
      <h2 id="summary-heading" className="font-semibold">
        {cart.shops.length === 1 ? "Your order" : `Your ${cart.shops.length} orders`}
      </h2>

      {cart.shops.length > 1 ? (
        <p className="text-muted-foreground text-sm">
          One order with each shop, each in its own currency.
        </p>
      ) : null}

      <ul className="space-y-3">
        {cart.shops.map((shop) => (
          <li key={shop.shop_slug} className="bg-card border-border rounded-lg border">
            <p className="border-border border-b px-4 py-2.5 text-sm font-semibold">
              {shop.shop_name}
            </p>
            <ul className="divide-border divide-y px-4">
              {shop.items.map((item) => (
                <li key={item.id} className="flex items-baseline justify-between gap-4 py-2.5">
                  <span className="min-w-0 text-sm">
                    <span className="block truncate">{item.product_name}</span>
                    <span className="text-muted-foreground block text-xs">
                      {item.variant_name}, quantity {item.quantity}
                    </span>
                  </span>
                  <span className="text-sm tabular-nums">
                    {formatMoney(item.line_total_minor, shop.currency)}
                  </span>
                </li>
              ))}
            </ul>
            <p className="border-border flex justify-between gap-4 border-t px-4 py-2.5 text-sm">
              <span className="text-muted-foreground">Subtotal</span>
              <span className="font-semibold tabular-nums">
                {formatMoney(shop.subtotal_minor, shop.currency)}
              </span>
            </p>
          </li>
        ))}
      </ul>
    </section>
  );
}
