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
