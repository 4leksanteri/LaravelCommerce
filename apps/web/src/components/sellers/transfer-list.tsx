import Link from "next/link";

import type { Transfer } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";
import { formatMoney } from "@/lib/money";

/**
 * What has actually reached the shop (ADR 0043).
 *
 * Three figures per row, because reconciling a payout needs all three: what the
 * buyer was charged, what the marketplace kept, and what arrived. A single
 * "amount" would leave a seller working out the difference themselves, and that
 * subtraction is exactly the kind of arithmetic the browser must not be doing
 * with money.
 *
 * **No total.** One shop trades in one currency so a sum would be a real number
 * here, and it is still the API's to compute rather than this component's
 * (`apps/web/CLAUDE.md` section 8). It arrives when there is a figure worth
 * showing, not because the rows happen to be addable.
 *
 * **Money being held is not in here.** A payout is money that moved; what is
 * still held sits on the order it belongs to, where a seller can see why.
 */
export function TransferList({ transfers }: { transfers: Transfer[] }) {
  if (transfers.length === 0) {
    return (
      <p className="text-muted-foreground text-sm">
        Nothing has been paid out yet. A shop is paid when a buyer confirms their parcel arrived,
        and each payout appears here.
      </p>
    );
  }

  return (
    <ul
      aria-label="Payouts"
      className="bg-card border-border divide-border divide-y rounded-lg border"
    >
      {transfers.map((transfer) => (
        <li key={transfer.order_reference} className="space-y-1 px-4 py-3">
          <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <Link
              href={`/seller/orders/${encodeURIComponent(transfer.order_reference)}`}
              className="font-medium hover:underline"
            >
              Order <span className="font-mono">{transfer.order_reference}</span>
            </Link>
            <span className="font-semibold tabular-nums">
              {formatMoney(transfer.amount_minor, transfer.currency)}
            </span>
          </div>
          <p className="text-muted-foreground flex flex-wrap justify-between gap-x-4 text-xs">
            <span>
              {transfer.transferred_at ? `Sent ${formatDate(transfer.transferred_at)}` : "Sent"}
            </span>
            <span className="tabular-nums">
              {formatMoney(transfer.charged_minor, transfer.currency)} charged, less{" "}
              {formatMoney(transfer.platform_fee_minor, transfer.currency)} fee
            </span>
          </p>
        </li>
      ))}
    </ul>
  );
}
