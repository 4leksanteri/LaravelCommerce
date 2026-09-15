import Link from "next/link";

import { AppealDecideActions } from "@/components/admin/appeal-decide-actions";
import type { Appeal } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";

/**
 * One appeal, with the decision it argues against. Renders the `<li>`, so it
 * goes straight inside a `<ul>`.
 *
 * **Both sides of the argument are here, and that is the whole point of the
 * card.** The platform's own words when it stopped the thing, and what the
 * person says about them. An appeal read without the sanction reason is half a
 * case, and staff have nowhere else to see either - there is no endpoint for a
 * single appeal, and this card is why one is not needed.
 *
 * **An appeal can outlive what it was about.** `appealable` is a morph and
 * carries no foreign key, so a seller may delete the listing they were
 * appealing about. `subject` is null then, and this says so rather than
 * rendering a blank row - somebody deleting what they were arguing to keep is
 * itself worth knowing, and it is why upholding is refused.
 */
export function AppealQueueCard({ appeal }: { appeal: Appeal }) {
  const subject = appeal.subject;

  return (
    <li className="bg-card border-border space-y-3 rounded-lg border p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <h2 className="text-lg font-semibold">
          {subject ? subject.title : "The thing this was about has gone"}
        </h2>
        {subject ? (
          <span className="text-muted-foreground text-sm uppercase">{subject.kind}</span>
        ) : null}
      </div>

      {subject ? null : (
        <p className="text-muted-foreground text-sm leading-relaxed">
          It was deleted after the appeal was raised. There is nothing left to put back, so this can
          only be left standing.
        </p>
      )}

      <dl className="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[10rem_minmax(0,1fr)]">
        <dt className="text-muted-foreground">Raised</dt>
        <dd>{appeal.raised_at ? formatDate(appeal.raised_at) : "Not recorded"}</dd>

        {subject?.sanction_reason ? (
          <>
            <dt className="text-muted-foreground">Why it was stopped</dt>
            <dd className="break-words whitespace-pre-wrap">{subject.sanction_reason}</dd>
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

      {/* Their argument, set apart from the platform's own words above it. */}
      <blockquote className="border-border text-sm leading-relaxed whitespace-pre-wrap border-l-2 pl-3">
        {appeal.reason}
      </blockquote>

      <AppealDecideActions appeal={appeal} />
    </li>
  );
}
