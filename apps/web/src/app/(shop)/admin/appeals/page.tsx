import type { Metadata } from "next";
import Link from "next/link";

import { AppealQueueCard } from "@/components/admin/appeal-queue-card";
import { Pagination } from "@/components/ui/pagination";
import { serverFetch } from "@/lib/api/server";
import type { AppealQueue } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = {
  title: "Appeals",
  robots: { index: false, follow: false },
};

/**
 * The platform's appeals queue: who has answered back, oldest first (ADR 0059).
 *
 * **Open ones only, and that is the API's list**, exactly as the dispute and
 * moderation queues are. A queue is a list of things to do, and a decided
 * appeal is not one - so there are no status filters here either.
 *
 * **Oldest first, and it costs more here than on the other two.** A shop
 * waiting on this one is suspended while it waits: it cannot publish, it is not
 * in the storefront, and it is losing money for every hour the queue is slow.
 *
 * Outside the account and shop layout, like the other three staff pages: staff
 * have no sidebar of their own, and building one for four pages would be a
 * shell put up before there is anything to fill it.
 *
 * Gated on `can_review_appeals`, which is its own answer rather than a reuse of
 * the other three - the API refuses the queue regardless of what this page
 * draws (ADR 0008).
 */
type Props = PageProps<"/admin/appeals">;

export default async function AppealQueuePage({ searchParams }: Props) {
  const params = await searchParams;
  const page = single(params.page);

  const user = await requireUser(`/admin/appeals${page ? `?page=${page}` : ""}`);

  if (!user.can_review_appeals) {
    return (
      <div className="mx-auto w-full max-w-3xl space-y-3 px-4 py-10">
        <h1 className="text-2xl font-bold tracking-tight">Appeals</h1>
        <p className="text-muted-foreground text-sm leading-relaxed">
          Platform staff take a second look when somebody says a suspension or a takedown was wrong.
          This account is not one, and there is nothing here for it.
        </p>
        <p className="text-sm">
          <Link href="/search" className="text-primary font-medium hover:underline">
            Browse the marketplace
          </Link>{" "}
          instead.
        </p>
      </div>
    );
  }

  const { data: appeals, meta } = await serverFetch<AppealQueue>(
    `/admin/appeals${page ? `?page=${encodeURIComponent(page)}` : ""}`,
  );

  return (
    <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8 sm:py-10">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Appeals</h1>
        <p className="text-muted-foreground text-sm">
          {meta.total === 1
            ? "1 appeal, the longest wait first"
            : `${meta.total} appeals, the longest wait first`}
        </p>
      </header>

      {appeals.length === 0 ? (
        <p className="text-muted-foreground text-sm">
          {meta.total > 0
            ? `There is nothing on page ${meta.current_page}.`
            : "Nothing is waiting. Every appeal has been decided."}
        </p>
      ) : (
        <ul aria-label="Appeals" className="space-y-4">
          {appeals.map((appeal) => (
            <AppealQueueCard key={appeal.id} appeal={appeal} />
          ))}
        </ul>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(target) => `/admin/appeals${target > 1 ? `?page=${target}` : ""}`}
      />
    </div>
  );
}

function single(value: string | string[] | undefined): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}
