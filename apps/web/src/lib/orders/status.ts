import type { OrderStatus } from "@/lib/api/types";

/**
 * Where an order has got to, in words.
 *
 * One place, so the checkout confirmation, the list of orders and an order's
 * own page all say the same thing. Exhaustive over the API's cases, so a sixth
 * status is a type error here rather than a blank on somebody's receipt.
 */
export function statusLabel(status: OrderStatus): string {
  switch (status) {
    case "pending":
      return "Waiting for the shop to accept it";
    case "accepted":
      return "Accepted by the shop";
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
