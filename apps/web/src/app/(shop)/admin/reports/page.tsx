import type { Metadata } from "next";
import Link from "next/link";

import { ReportQueueCard } from "@/components/admin/report-queue-card";
import { Pagination } from "@/components/ui/pagination";
import { serverFetch } from "@/lib/api/server";
import type { ReportQueue } from "@/lib/api/types";
import { requireUser } from "@/lib/auth/session";

export const metadata: Metadata = {
  title: "Reports",
  robots: { index: false, follow: false },
};

/**
 * The platform's moderation queue: what is still open, oldest first (ADR 0054).
 *
 * **Open ones only, and that is the API's list.** A queue is a list of things to
 * do, and a decided report is not one. So there are no status filters here,
 * exactly as on the dispute queue.
 *
 * **Oldest first**, because something flagged as counterfeit has been on sale
 * for every hour it waits - which is the whole cost of this queue being slow.
 *
 * Outside the account and shop layout, like the other two staff pages: staff
 * have no sidebar of their own, and building one for three pages would be a
 * shell put up before there is anything to fill it.
 *
 * Gated on `can_review_reports`, which is its own answer rather than a reuse of
 * `can_review_disputes` - the API refuses the queue regardless of what this
 * page draws (ADR 0008).
 */
type Props = PageProps<"/admin/reports">;

export default async function ReportQueuePage({ searchParams }: Props) {
  const params = await searchParams;
  const page = single(params.page);

  const user = await requireUser(`/admin/reports${page ? `?page=${page}` : ""}`);

  if (!user.can_review_reports) {
    return (
      <div className="mx-auto w-full max-w-3xl space-y-3 px-4 py-10">
        <h1 className="text-2xl font-bold tracking-tight">Reports</h1>
        <p className="text-muted-foreground text-sm leading-relaxed">
          Platform staff decide what happens to a listing or a review somebody has reported. This
          account is not one, and there is nothing here for it.
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

  const { data: reports, meta } = await serverFetch<ReportQueue>(
    `/admin/reports${page ? `?page=${encodeURIComponent(page)}` : ""}`,
  );

  return (
    <div className="mx-auto w-full max-w-5xl space-y-6 px-4 py-8 sm:py-10">
      <header className="space-y-1.5">
        <h1 className="text-2xl font-bold tracking-tight">Reports</h1>
        <p className="text-muted-foreground text-sm">
          {meta.total === 1
            ? "1 report, the longest wait first"
            : `${meta.total} reports, the longest wait first`}
        </p>
      </header>

      {reports.length === 0 ? (
        <p className="text-muted-foreground text-sm">
          {meta.total > 0
            ? `There is nothing on page ${meta.current_page}.`
            : "Nothing is waiting. Every report has been decided."}
        </p>
      ) : (
        <ul aria-label="Reports" className="space-y-4">
          {reports.map((report) => (
            <ReportQueueCard key={report.id} report={report} />
          ))}
        </ul>
      )}

      <Pagination
        currentPage={meta.current_page}
        lastPage={meta.last_page}
        hrefFor={(target) => `/admin/reports${target > 1 ? `?page=${target}` : ""}`}
      />
    </div>
  );
}

function single(value: string | string[] | undefined): string | undefined {
  return typeof value === "string" && value !== "" ? value : undefined;
}
