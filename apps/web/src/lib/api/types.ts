/**
 * The shapes the API returns.
 *
 * Hand-written today, and that is a known weakness rather than a design: a
 * type here can drift from the Laravel resource that produces it and nothing
 * will notice until something renders `undefined`. The intended direction is
 * to generate this file from an OpenAPI document the API publishes, at which
 * point it stops being editable by hand. See ADR 0003.
 *
 * Until then, changing a resource in apps/api means changing the matching type
 * here in the same commit.
 */

/** GET /api/v1/health */
export type HealthStatus = {
  status: "ok";
};

/** GET /api/v1/auth/me */
export type AuthenticatedUser = {
  id: number;
  name: string;
  email: string;
  email_verified_at: string | null;
  created_at: string | null;
};

/** Laravel wraps a single resource in `data`. */
export type Resource<T> = {
  data: T;
};
