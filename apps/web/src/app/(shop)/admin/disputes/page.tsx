import type { Metadata } from "next";
import Link from "next/link";

import { DisputeQueueCard } from "@/components/admin/dispute-queue-card";
import { Pagination } from "@/components/ui/pagination";
import { serverFetch } from "@/lib/api/server";
import type { DisputeQueue } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = {
  title: "Disputes",
  robots: { index: false, follow: false },
};

/**
 * The platform's dispute queue: what is still open, oldest first (ADR 0051).
 *
 * **Open ones only, and that is the API's list.** A queue is a list of things
 * to do, and a decided dispute is not one - it is read on the order it belongs
 * to, where both parties see it too. So there are no status filters here,
 * unlike the shop review queue, and nothing to narrow.
 *
 * **Oldest first**, because somebody has been waiting on their money since the
 * day they opened it, and because their order cannot complete while it stands.
 *
 * Outside the account and shop layout, like the review queue: staff pages have
 * no sidebar of their own, and building one for two pages would be a shell put
 * up before there is anything to fill it.
 *
 * **The refusal is explained rather than hidden.** Somebody who is not staff is
 * told what this page is; the API refuses the queue itself regardless of what
 * this page draws (ADR 0008).
 */
type Props = PageProps<"/admin/disputes">;

export default async function DisputeQueuePage({ searchParams }: Props) {
  const params = await searchParams;
  const page = single(params.page);

  const user = await requireUser(`/admin/disputes${page ? `?page=${page}` : ""}`);

  if (!user.can_review_disputes) {
    return (
      <div className="mx-auto w-full max-w-3xl space-y-3 px-4 py-10">
        <h1 className="text-2xl font-bold tracking-tight">Disputes</h1>
        <p className="text-muted-foreground text-sm leading-relaxed">
          Platform staff decide disputes between a buyer and a shop. This account is not one, and
          there is nothing here for it.
        </p>
        <p className="text-sm">
          <Link href="/account/orders" className="text-primary font-medium hover:underline">
            Your own orders
          </Link>{" "}
          is where anything you have bought lives.
        </p>
      </div>
    );
  }

  const { data: disputes, meta } = await serverFetch<DisputeQueue>(
    `/admin/disputes${page ? `?page=${encodeURIComponent(page)}` : ""}`,
  );

  return (
    <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8 sm:py-10">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Disputes</h1>
        <p className="text-muted-foreground text-sm">
          {meta.total === 1
            ? "1 dispute, the longest wait first"
            : `${meta.total} disputes, the longest wait first`}
        </p>
      </header>

      {disputes.length === 0 ? (
        <p className="text-muted-foreground text-sm">
          {meta.total > 0
            ? `There is nothing on page ${meta.current_page}.`
            : "Nothing is waiting. Every dispute has been decided."}
        </p>
      ) : (
        <ul aria-label="Disputes" className="space-y-4">
          {disputes.map((dispute) => (
            <DisputeQueueCard key={dispute.id} dispute={dispute} />
          ))}
        </ul>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(target) => `/admin/disputes${target > 1 ? `?page=${target}` : ""}`}
      />
    </div>
  );
}

function single(value: string | string[] | undefined): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}
