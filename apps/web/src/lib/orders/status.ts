import type { OrderStatus } from "@/lib/api/types";

/** Which side of an order is reading about it. The words differ for each. */
export type OrderReader = "buyer" | "shop";

/**
 * Where an order has got to, in words, for whoever is reading.
 *
 * The same status reads differently to each side. A pending order is "waiting
 * for the shop to accept it" to its buyer and "to accept" to the shop, whose
 * list of orders is a list of things to do (ADR 0036). One place for both, so
 * the confirmation, both lists and both order pages say the same things.
 *
 * Exhaustive over the API's cases, so a sixth status is a type error here
 * rather than a blank on somebody's receipt.
 */
export function statusLabel(status: OrderStatus, reader: OrderReader = "buyer"): string {
  switch (status) {
    case "pending":
      return reader === "shop" ? "To accept" : "Waiting for the shop to accept it";
    case "accepted":
      return reader === "shop" ? "To send" : "Accepted by the shop";
    case "shipped":
      return "Sent";
    case "completed":
      return "Completed";
    case "cancelled":
      return "Cancelled";
    default: {
      const unhandled: never = status;

      return unhandled;
    }
  }
}

/**
 * Every status, in the order an order passes through them. A record's keys
 * rather than a hand-written list, so a status the API adds is a type error
 * here until it has a place.
 */
const IN_ORDER: Record<OrderStatus, true> = {
  pending: true,
  accepted: true,
  shipped: true,
  completed: true,
  cancelled: true,
};

export const ORDER_STATUSES = Object.keys(IN_ORDER) as OrderStatus[];
