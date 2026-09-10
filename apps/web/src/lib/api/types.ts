import type { components, operations } from "./generated/schema";

/**
 * Domain names for the shapes the API returns.
 *
 * **Named aliases only.** Nothing here describes a shape by hand; every type
 * below points at `generated/schema.d.ts`, which is generated from the
 * OpenAPI document the API publishes. That is the whole point: a hand-written
 * copy stops matching the backend silently, and this pipeline exists to make
 * that impossible.
 *
 * The alias layer is not ceremony. It gives generated shapes readable names,
 * and it means a change in how the generator names things is one edit here
 * rather than one in every component.
 *
 * To change a shape, change the Laravel resource or form request and run:
 *
 *     make api-docs      regenerate the spec and these types
 *     make api-check     fails when the committed output has drifted
 *
 * See ADR 0006.
 */

type Schemas = components["schemas"];

/** Laravel wraps a single resource in `data`. */
export type Resource<T> = { data: T };

// --- Users ------------------------------------------------------------------

export type AuthenticatedUser = Schemas["UserResource"];

// --- Shops ------------------------------------------------------------------
//
// `Currency` and `SellerStatus` are unions of the actual cases, not `string`,
// because the Laravel resources return the enums themselves rather than their
// values. That is what lets a component switch on a status exhaustively and
// have TypeScript complain when a case is added.

export type Currency = Schemas["Currency"];
export type SellerStatus = Schemas["SellerStatus"];

/** A shop as its owner or a reviewer sees it, review state included. */
export type Shop = Schemas["SellerResource"];

/** A shop as a shopper sees it. A much shorter allowlist - no review state. */
export type PublicShop = Schemas["PublicShopResource"];

export type ShopApplication = Schemas["ApplyToSellRequest"];
export type ShopEdit = Schemas["UpdateShopRequest"];
export type ShopRejection = Schemas["RejectSellerRequest"];
export type ShopPage = Schemas["SellerCollection"];

// --- Products ---------------------------------------------------------------
//
// There is no `price` on a product. A listing with two sizes has two prices,
// and they live on its variants - the only place a price ever is (ADR 0009).
// `price_minor` is an integer number of minor units: 2499 is 24.99 in EUR and
// 2499 yen in JPY. Never divide it by 100 without asking the currency.

export type ProductStatus = Schemas["ProductStatus"];

/** A listing as its seller sees it: drafts, stock and `can_*` included. */
export type Product = Schemas["ProductResource"];
export type ProductVariant = Schemas["ProductVariantResource"];
export type ProductPage = Schemas["ProductCollection"];

/** A listing as a shopper sees it. No status, no stock counts. */
export type PublicProduct = Schemas["PublicProductResource"];
export type PublicProductPage = Schemas["PublicProductCollection"];

export type NewProduct = Schemas["StoreProductRequest"];
export type ProductEdit = Schemas["UpdateProductRequest"];
export type NewVariant = Schemas["StoreVariantRequest"];
export type VariantEdit = Schemas["UpdateVariantRequest"];

// --- Cart -------------------------------------------------------------------
//
// A cart is grouped by shop, and there is deliberately no grand total on it.
// Each shop prices in its own currency, so a figure spanning two of them is not
// a number (ADR 0004) - `CartShop.subtotal_minor` is the only total there is,
// one per shop, and each shop becomes its own order and its own payment.
//
// Two prices sit on every line and they are not interchangeable.
// `unit_price_minor` is read from the variant and is what checkout will charge;
// `added_price_minor` is a snapshot of what it cost when it went in the cart,
// and exists only so `price_changed` can be shown. Never total the snapshot.

export type Cart = Schemas["CartResource"];
export type CartShop = Schemas["CartShopResource"];
export type CartItem = Schemas["CartItemResource"];

/**
 * Why a line cannot be bought, or that it can. A union of the four cases rather
 * than `string`, so a component switching on it is exhaustive and TypeScript
 * complains when a case is added.
 */
export type CartItemAvailability = Schemas["CartItemAvailability"];

export type NewCartItem = Schemas["AddCartItemRequest"];
export type CartItemEdit = Schemas["SetCartItemQuantityRequest"];

/**
 * The 409 from adding or re-quantifying a line: for sale, but not that many -
 * or no longer for sale at all. `available` is how many can be had, and is null
 * when the reason is not stock.
 */
export type NotPurchasable =
  operations["cart.items.store"]["responses"][409]["content"]["application/json"];

// --- Orders -----------------------------------------------------------------
//
// The mirror image of the cart, and the difference is the whole point. A cart
// line reads its price from the catalogue every time it is shown; an order line
// never does again. So there is no `availability` and no `price_changed` on an
// order - a receipt does not move when a shop does (ADR 0011).
//
// One order per shop, so `total_minor` is always in that one shop's `currency`
// and there is no total spanning two of them, here or anywhere.
//
// An order is addressed by `reference`, not by id. That is the string in the
// URL and the one a person quotes. `checkout_reference` is a different thing:
// it is shared by every order one checkout produced, so a history page can show
// that three of them were one purchase. Nothing groups by it server-side yet -
// `GET /orders` is a flat paginated list.

export type Order = Schemas["OrderResource"];
export type OrderItem = Schemas["OrderItemResource"];
export type OrderPage = Schemas["OrderCollection"];
export type OrderStatus = Schemas["OrderStatus"];

/**
 * The 409 from checkout: the cart was empty, or some of it can no longer be
 * bought. **Nothing was ordered** - not even the shops whose lines were fine.
 * `items` names the lines that blocked it so they can be marked in place, and
 * is empty when the cart itself was.
 */
export type CheckoutBlocked =
  operations["checkout"]["responses"][409]["content"]["application/json"];

// --- Authentication requests ------------------------------------------------

export type RegistrationDetails = Schemas["RegisterRequest"];
export type LoginCredentials = Schemas["LoginRequest"];
export type ForgotPasswordDetails = Schemas["ForgotPasswordRequest"];
export type ResetPasswordDetails = Schemas["ResetPasswordRequest"];

// --- Responses read straight off an operation -------------------------------
//
// Some endpoints answer with an inline shape rather than a named resource, so
// there is no components["schemas"] entry to alias. Reading it off the
// operation keeps the type generated rather than retyped.

export type HealthStatus = operations["health"]["responses"][200]["content"]["application/json"];

export type EmailVerificationResult =
  operations["auth.email.verify"]["responses"][200]["content"]["application/json"];

/**
 * Laravel's 422 body. Field errors belong beside the field that caused them,
 * which is what `ApiError.validationErrors` returns.
 */
export type ValidationErrors = Record<string, string[]>;
