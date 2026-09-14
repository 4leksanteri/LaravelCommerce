import Link from "next/link";

import { ReportDecideActions } from "@/components/admin/report-decide-actions";
import type { Report, ReportReason } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";

/**
 * One report, with what it points at. Renders the `<li>`, so it goes straight
 * inside a `<ul>`.
 *
 * **The subject is summarised here because staff cannot otherwise see it**, the
 * same reason `DisputeQueueCard` carries the order around a dispute. There is no
 * endpoint for a single report, and this card is why one is not needed.
 *
 * **A report can outlive what it pointed at.** `reportable` is a morph and
 * carries no foreign key, so a seller may delete a flagged listing between the
 * report and the decision. `subject` is null then, and this says so rather than
 * rendering a blank row - a shop deleting what it was reported for is itself
 * worth knowing.
 *
 * Who reported it is not published, and so is not drawn. Moderation that names
 * its reporter is moderation nobody uses twice.
 */
const REASONS: Record<ReportReason, string> = {
  counterfeit: "Not what it claims to be",
  prohibited: "Should not be sold here",
  abusive: "Abusive",
  spam: "Spam, or posted over and over",
  other: "Something else",
};

export function ReportQueueCard({ report }: { report: Report }) {
  const subject = report.subject;

  return (
    <li className="bg-card border-border space-y-3 rounded-lg border p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="text-lg font-semibold">
          {subject ? subject.title : "The thing this was about has gone"}
        </h2>
        <span className="text-muted-foreground text-sm">{REASONS[report.reason]}</span>
      </div>

      {subject ? (
        <p className="text-muted-foreground text-xs uppercase">{subject.kind}</p>
      ) : (
        <p className="text-muted-foreground text-sm leading-relaxed">
          It was deleted after it was reported. There is nothing left to take down, so this can only
          be dismissed.
        </p>
      )}

      {subject?.body ? (
        <blockquote className="border-border text-sm leading-relaxed whitespace-pre-wrap border-l-2 pl-3">
          {subject.body}
        </blockquote>
      ) : null}

      <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[8rem_minmax(0,1fr)]">
        <dt className="text-muted-foreground">Reported</dt>
        <dd>{report.reported_at ? formatDate(report.reported_at) : "Not recorded"}</dd>

        {report.note ? (
          <>
            <dt className="text-muted-foreground">In their words</dt>
            <dd className="break-words whitespace-pre-wrap">{report.note}</dd>
          </>
        ) : null}

        {subject?.href ? (
          <>
            <dt className="text-muted-foreground">Where</dt>
            <dd>
              <Link href={subject.href} className="text-primary font-medium hover:underline">
                See it as a shopper does
              </Link>
            </dd>
          </>
        ) : null}
      </dl>

      <ReportDecideActions report={report} />
    </li>
  );
}
