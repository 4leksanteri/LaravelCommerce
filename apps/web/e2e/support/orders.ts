import { expect, type Browser, type Page } from "@playwright/test";

import { apiCall, asSeller } from "./session";

type Listing = { shop: string; product: string; variant: string };

/**
 * What the order tests buy: the Seiko 5 on its canvas strap. Nothing else in
 * the suite wants it, and `make seed-demo` puts its stock back before every
 * run.
 *
 * **It is stocked far deeper than a run appears to need, on purpose.** Two of
 * the things this suite proves keep their stock for good: completing an order
 * does not give it back, and neither does cancelling one that has already been
 * sent (ADR 0011). So every run consumes at least two units permanently, and a
 * run that fails part way can leave a pending order holding another.
 *
 * It was stocked three deep until that arithmetic ran out mid-run, and the
 * failure landed on an unrelated spec as "adding it to the cart" - which reads
 * like anything but a fixture that was one order from empty.
 */
export const SEIKO: Listing = {
  shop: "second-hand-time",
  product: "seiko-5-automatic-snk809",
  variant: "Canvas strap",
};

/**
 * Places an order through the API, as whoever the page is signed in as.
 *
 * For tests about what happens to an order afterwards. Checkout itself is
 * driven through its page in checkout.spec; doing it again here would test it
 * twice, and make every order test fail whenever the checkout form changed.
 *
 * The variant is chosen by name, so a listing with two options buys the one
 * the test meant rather than whichever the API happens to list first.
 */
export async function placeOrder(page: Page, listing: Listing): Promise<string> {
  const product = await apiCall(page, "GET", `/shops/${listing.shop}/products/${listing.product}`);
  expect(product.ok(), "reading the listing").toBe(true);

  const { variants } = ((await product.json()) as { data: { variants: Variant[] } }).data;
  const variant = variants.find((candidate) => candidate.name === listing.variant);

  if (!variant) {
    throw new Error(`${listing.product} has no option called "${listing.variant}".`);
  }

  const added = await apiCall(page, "POST", "/cart/items", { variant_id: variant.id, quantity: 1 });
  expect(added.ok(), "adding it to the cart").toBe(true);

  const placed = await apiCall(page, "POST", "/checkout", { address_id: await anAddress(page) });
  expect(placed.status(), "checking out").toBe(201);

  const orders = ((await placed.json()) as { data: { reference: string }[] }).data;

  return orders[0].reference;
}

/**
 * Leaves an order finished, whatever state a failed test left it in.
 *
 * Cancelled by the buyer while it is still pending, and by the shop once it
 * has got further, because that is who may. Stock comes back either way unless
 * it had been sent, and `make seed-demo` restores the rest before the next run.
 */
export async function finishOrder(page: Page, browser: Browser, reference: string): Promise<void> {
  const response = await apiCall(page, "GET", `/orders/${reference}`);
  const { status } = ((await response.json()) as { data: { status: string } }).data;

  if (status === "pending") {
    const cancelled = await apiCall(page, "POST", `/orders/${reference}/cancellation`);
    expect(cancelled.ok(), `cancelling ${reference} as the buyer`).toBe(true);
  }

  if (status === "accepted" || status === "shipped") {
    await asSeller(browser, async (shop) => {
      // A shop cancelling says why (ADR 0035).
      const cancelled = await apiCall(shop, "POST", `/seller/orders/${reference}/cancellation`, {
        reason: "Tidying up after an end-to-end test.",
      });
      expect(cancelled.ok(), `cancelling ${reference} as the shop`).toBe(true);
    });
  }
}

type Variant = { id: number; name: string };

async function anAddress(page: Page): Promise<number> {
  const book = (await (await apiCall(page, "GET", "/addresses")).json()) as {
    data: { id: number }[];
  };

  if (book.data[0]) {
    return book.data[0].id;
  }

  const created = await apiCall(page, "POST", "/addresses", {
    name: "Demo Shopper",
    line1: "Testikatu 1",
    city: "Helsinki",
    country: "FI",
  });
  expect(created.status(), "saving an address").toBe(201);

  return ((await created.json()) as { data: { id: number } }).data.id;
}
