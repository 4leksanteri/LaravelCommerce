import "server-only";

import { unstable_rethrow } from "next/navigation";
import { cache } from "react";

import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { Shop } from "@/lib/api/types";

/**
 * The signed-in person's shop, at any status, or null when they have none.
 *
 * `GET /seller` answers `data: null` for an account without a shop rather than
 * a 403, because "do I have one" is a question and "no" is not an error
 * (ShopController). Signed out is null as well: every page that asks has
 * already sent that person to sign in.
 *
 * Wrapped in React's `cache`, because the account layout draws the shop in its
 * sidebar and the page beside it usually needs the same shop. One request to
 * the page, one read of the shop.
 */
export const readShop = cache(async (): Promise<Shop | null> => {
  try {
    return (await serverFetch<{ data: Shop | null }>("/seller")).data;
  } catch (error) {
    unstable_rethrow(error);

    if (error instanceof ApiError && error.isUnauthenticated) {
      return null;
    }

    throw error;
  }
});
