import type { Metadata } from "next";
import { redirect, unstable_rethrow } from "next/navigation";

import { ShopOrderCard } from "@/components/sellers/shop-order-card";
import { Pagination } from "@/components/ui/pagination";
import { PillLink } from "@/components/ui/pill-link";
import { ApiError } from "@/lib/api/errors";
import { serverFetch } from "@/lib/api/server";
import type { OrderStatus, ShopOrders } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";
import { ORDER_STATUSES, statusLabel } from "@/lib/orders/status";
import { readShop } from "@/lib/sellers/shop";

export const metadata: Metadata = {
  title: "Orders",
  robots: { index: false, follow: false },
};

/**
 * What has been bought from the shop, newest first, and narrowed to one status
 * when asked (ADR 0036).
 *
 * **The filters are the shop's to-do list.** "To accept" and "to send" are the
 * two statuses that are waiting on the shop, and they are the first two after
 * "all". Each is a link with an address, so a shop can keep one open, and the
 * narrowing is the API's (`?status=`), inside the shop's own orders.
 *
 * **Paged by `meta`**, as every list here is. A status the API does not know is
 * a 422, which only a hand-edited address produces, so it is sent back to the
 * whole list rather than drawn as an error.
 */
type Props = PageProps<"/seller/orders">;

export default async function ShopOrdersPage({ searchParams }: Props) {
  const params = await searchParams;
  const status = single(params.status);
  const page = single(params.page);

  await requireUser(`/seller/orders${queryOf(status, page)}`);

  const shop = await readShop();

  if (!shop) {
    redirect("/sell");
  }

  const { data: orders, meta } = await readOrders(status, page);
  const selected = ORDER_STATUSES.find((candidate) => candidate === status) ?? null;

  return (
    <div className="space-y-6">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Orders</h1>
        <p className="text-muted-foreground text-sm">
          {selected
            ? `${statusLabel(selected, "shop")}: ${meta.total === 1 ? "1 order" : `${meta.total} orders`}`
            : meta.total === 1
              ? "1 order, newest first"
              : `${meta.total} orders, newest first`}
        </p>
      </header>

      <nav aria-label="Filter by status">
        <ul className="flex flex-wrap gap-2">
          <li>
            <PillLink href="/seller/orders" active={selected === null}>
              All
            </PillLink>
          </li>
          {ORDER_STATUSES.map((candidate) => (
            <li key={candidate}>
              <PillLink href={`/seller/orders?status=${candidate}`} active={selected === candidate}>
                {statusLabel(candidate, "shop")}
              </PillLink>
            </li>
          ))}
        </ul>
      </nav>

      {orders.length === 0 ? (
        <p className="text-muted-foreground text-sm">
          {meta.total > 0
            ? `There is nothing on page ${meta.current_page}.`
            : selected
              ? "No orders here."
              : "No orders yet. They appear here as shoppers buy."}
        </p>
      ) : (
        <ul aria-label="Orders" className="space-y-3">
          {orders.map((order) => (
            <ShopOrderCard key={order.reference} order={order} />
          ))}
        </ul>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(target) =>
          `/seller/orders${queryOf(selected ?? undefined, target > 1 ? String(target) : undefined)}`
        }
      />
    </div>
  );
}

async function readOrders(status: string | undefined, page: string | undefined) {
  try {
    return await serverFetch<ShopOrders>(`/seller/orders${queryOf(status, page)}`);
  } catch (error) {
    unstable_rethrow(error);

    if (error instanceof ApiError && error.status === 422) {
      redirect("/seller/orders");
    }

    throw error;
  }
}

function queryOf(status: OrderStatus | string | undefined, page: string | undefined): string {
  const query = new URLSearchParams();

  if (status) {
    query.set("status", status);
  }

  if (page) {
    query.set("page", page);
  }

  const encoded = query.toString();

  return encoded ? `?${encoded}` : "";
}

function single(value: string | string[] | undefined): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}
