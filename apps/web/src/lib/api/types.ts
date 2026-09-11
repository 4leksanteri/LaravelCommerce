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

// --- Payouts ----------------------------------------------------------------
//
// A shop's Stripe connected account, as its owner sees it (ADR 0031). Every
// key is present whether or not an account has been opened, so a page reads
// `status` and `can_open` without first checking what exists.
//
// `due` is in this API's words rather than Stripe's: one `date_of_birth`, not
// three of Stripe's paths. Each PayoutField is also the key the update takes,
// except `identity_document`, which is a multipart endpoint of its own.
// `unsupported` is what Stripe asked for that no field answers, and a page
// should say so rather than hide it.

export type PayoutAccount = Schemas["PayoutAccountResource"];
export type PayoutStatus = Schemas["PayoutStatus"];
export type PayoutField = Schemas["PayoutField"];
export type PayoutCountry = Schemas["PayoutCountry"];
export type PayoutAccountOpening = Schemas["OpenPayoutAccountRequest"];
export type PayoutDetails = Schemas["UpdatePayoutDetailsRequest"];

// --- Products ---------------------------------------------------------------
//
// There is no `price` on a product. A listing with two sizes has two prices,
// and they live on its variants - the only place a price ever is (ADR 0009).
// `price_minor` is an integer number of minor units: 2499 is 24.99 in EUR and
// 2499 yen in JPY. Never divide it by 100 without asking the currency.

export type ProductStatus = Schemas["ProductStatus"];

/**
 * What kind of thing a listing is. Staff own this list; sellers choose from it.
 *
 * Two levels at most, so `children` is the whole subtree and never needs
 * recursing more than once. It is empty on a product's own category and
 * populated on `GET /categories`, which is the navigation.
 *
 * A listing may be drafted without one but not published without one - a
 * listing nobody can find is not on sale (ADR 0017).
 */
export type Category = Schemas["CategoryResource"];
export type CategoryTree = Schemas["CategoryCollection"];

/**
 * A product photograph. Always WebP, always something this API produced from
 * whatever was uploaded (ADR 0016).
 *
 * `url` is **relative** - `/api/v1/images/{key}` - so the browser resolves it
 * against this origin and the proxy forwards it. That also means `next/image`
 * treats it as local and needs no `remotePatterns` entry.
 *
 * `width` and `height` are the stored dimensions and are here so a client can
 * reserve the space before the bytes arrive. Pass them to `next/image`
 * directly; there is no second set of sizes to choose from, because the
 * optimiser derives the responsive set from this one source.
 *
 * `alt_text` is null when the seller did not write one. Render it as an empty
 * alt only if you mean "decorative", which a product photograph is not.
 */
export type ProductImage = Schemas["ProductImageResource"];

/** A listing as its seller sees it: drafts, stock and `can_*` included. */
export type Product = Schemas["ProductResource"];
export type ProductVariant = Schemas["ProductVariantResource"];
export type ProductPage = Schemas["ProductCollection"];

/** A listing as a shopper sees it. No status, no stock counts. */
export type PublicProduct = Schemas["PublicProductResource"];
export type PublicProductPage = Schemas["PublicProductCollection"];

/**
 * One page of `GET /search`: the listings, and `meta` saying where in the set
 * this page sits. Read off the operation rather than composed by hand, so the
 * four numbers ADR 0022 settled on arrive exactly as the API published them.
 */
export type SearchResults = operations["search"]["responses"][200]["content"]["application/json"];
export type PageMeta = SearchResults["meta"];

/**
 * One page of a category's listings, subcategories included (ADR 0017). The
 * same shape as a search, from an endpoint that answers 404 for a category that
 * does not exist rather than a 422.
 */
export type CategoryListings =
  operations["categories.products"]["responses"][200]["content"]["application/json"];

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

/**
 * `pending -> accepted -> shipped -> completed`, with `cancelled` reachable
 * from the first three. A union of the five cases rather than `string`, so a
 * component switching on it is exhaustive.
 *
 * A shipped order completes on `auto_complete_at` whether or not the buyer
 * confirms. `can_extend_completion` says whether they may push that back
 * because their parcel is late, and `completion_extensions_left` says how many
 * times - both answers, never a rule to re-derive (ADR 0014).
 */
export type OrderStatus = Schemas["OrderStatus"];

/**
 * The same order as the shop that received it sees it.
 *
 * A different allowlist, not a variation: `buyer_name` is here and
 * `checkout_reference` deliberately is not - a seller has no business knowing
 * their buyer was shopping elsewhere at that moment (ADR 0011).
 *
 * The `can_*` fields answer for the viewer, so the same order gives a buyer and
 * a seller different answers: once accepted, only the seller may cancel. Draw
 * buttons from these, never from `status` plus a rule copied into the browser.
 */
export type SellerOrder = Schemas["SellerOrderResource"];
export type SellerOrderPage = Schemas["SellerOrderCollection"];

/**
 * The 409 from any order transition: the order has moved past what was asked.
 * `status` is where it is now, so the order can be re-rendered without fetching
 * it again - which is usually how this happened, a button drawn from state that
 * had since changed.
 */
export type OrderTransitionRefused =
  operations["orders.cancel"]["responses"][409]["content"]["application/json"];

/**
 * The 409 from checkout: the cart was empty, or some of it can no longer be
 * bought. **Nothing was ordered** - not even the shops whose lines were fine.
 * `items` names the lines that blocked it so they can be marked in place, and
 * is empty when the cart itself was.
 */
export type CheckoutBlocked =
  operations["checkout"]["responses"][409]["content"]["application/json"];

// --- Addresses and checkout -------------------------------------------------
//
// An address-book entry is editable; the copy an order freezes at checkout is
// not (ADR 0021). They are different shapes on purpose: an order's
// `shipping_address` is read from the order's own columns and is never an
// Address, so moving house cannot rewrite where a parcel went.

export type Address = Schemas["AddressResource"];
export type AddressBook = Schemas["AddressCollection"];
export type NewAddress = Schemas["StoreAddressRequest"];

/**
 * What stands between a basket and a checkout, or null when nothing does. The
 * API's answer, so the checkout page never re-derives "needs a confirmed email"
 * from `email_verified_at` (ADR 0030). A union of the cases, so a page switching
 * on it is exhaustive.
 */
export type CheckoutBlocker = Schemas["CheckoutBlocker"];

/** The orders one checkout created, one per shop. Every one of them, not a page. */
export type PlacedOrders = Schemas["PlacedOrderCollection"];

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
