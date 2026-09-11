import type { Order } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";
import { cn } from "@/lib/utils";

/**
 * What has happened to an order, and what it is waiting for.
 *
 * Read off the order's own timestamps: a step with a date has happened, the
 * first one without a date is what the order is waiting for, and the rest have
 * not happened yet. Nothing here decides what anybody may do. The buttons are
 * OrderActions, drawn from the API's `can_*` answers.
 *
 * **It does not say who.** A cancelled order shows that it was cancelled and
 * when, not by whom, and a completed one does not say whether the buyer
 * confirmed it or the deadline passed. The API records neither yet (root
 * CLAUDE.md section 20), and a page that guessed would be making it up.
 *
 * The design export's timeline has "Delivered" and a courier's tracking number.
 * Nothing records either - shipping is a `shipped_at` and no more - so there is
 * no step for them.
 */
type Milestone = "placed" | "accepted" | "sent" | "completed";

type Step = {
  title: string;
  at: string | null;
  note: string | null;
  state: "done" | "current" | "todo" | "cancelled";
};

export function OrderTimeline({ order }: { order: Order }) {
  const steps = stepsOf(order);

  return (
    <ol aria-label="Progress">
      {steps.map((step, index) => (
        <li key={step.title} className="relative flex gap-3 pb-6 last:pb-0">
          {index < steps.length - 1 ? (
            <span
              aria-hidden="true"
              className={cn(
                "absolute top-6 bottom-0 left-[11px] w-0.5",
                step.state === "done" ? "bg-primary" : "bg-border",
              )}
            />
          ) : null}

          <span
            aria-hidden="true"
            className={cn(
              "relative flex size-6 shrink-0 items-center justify-center rounded-full border-2",
              MARKER[step.state],
            )}
          >
            {step.state === "done" ? <Tick /> : null}
            {step.state === "current" ? <span className="bg-caution size-2 rounded-full" /> : null}
          </span>

          <div className="min-w-0 flex-1 pt-0.5">
            <p className="flex flex-wrap items-baseline justify-between gap-x-3">
              <span
                className={cn(
                  "text-sm font-semibold",
                  step.state === "todo" && "text-muted-foreground",
                )}
              >
                <span className="sr-only">{SPOKEN[step.state]}: </span>
                {step.title}
              </span>
              {step.at ? (
                <time dateTime={step.at} className="text-muted-foreground text-xs">
                  {formatDate(step.at)}
                </time>
              ) : null}
            </p>
            {step.note ? (
              <p className="text-muted-foreground mt-0.5 text-sm leading-relaxed">{step.note}</p>
            ) : null}
          </div>
        </li>
      ))}
    </ol>
  );
}

function stepsOf(order: Order): Step[] {
  const milestones: { key: Milestone; title: string; at: string | null }[] = [
    { key: "placed", title: "Placed", at: order.placed_at },
    { key: "accepted", title: "Accepted", at: order.accepted_at },
    { key: "sent", title: "Sent", at: order.shipped_at },
    { key: "completed", title: "Completed", at: order.completed_at },
  ];

  // What happened, and then the end of it. The steps it never reached are left
  // out rather than drawn as still to come.
  if (order.status === "cancelled") {
    return [
      ...milestones
        .filter((milestone) => milestone.at !== null)
        .map((milestone): Step => ({ ...milestone, note: null, state: "done" })),
      {
        title: "Cancelled",
        at: order.cancelled_at,
        note: "Nothing more will happen to this order.",
        state: "cancelled",
      },
    ];
  }

  const next = milestones.findIndex((milestone) => milestone.at === null);

  return milestones.map((milestone, index): Step => ({
    title: milestone.title,
    at: milestone.at,
    note: index === next ? waitingFor(milestone.key, order) : null,
    state: milestone.at !== null ? "done" : index === next ? "current" : "todo",
  }));
}

function waitingFor(milestone: Milestone, order: Order): string | null {
  switch (milestone) {
    case "accepted":
      return `Waiting for ${order.shop_name} to accept it.`;
    case "sent":
      return `Waiting for ${order.shop_name} to send it.`;
    case "completed":
      return order.auto_complete_at
        ? `Confirm it arrived once you have checked it over. If you do not, it completes on its own on ${formatDate(order.auto_complete_at)}.`
        : "Confirm it arrived once you have checked it over.";
    case "placed":
      return null;
    default: {
      const unhandled: never = milestone;

      return unhandled;
    }
  }
}

const MARKER: Record<Step["state"], string> = {
  done: "border-primary bg-primary text-primary-foreground",
  current: "border-caution bg-card",
  todo: "border-border bg-card",
  cancelled: "border-muted-foreground bg-muted-foreground",
};

// Said to a screen reader before each step's title, because the marker that
// shows it to everybody else is decoration.
const SPOKEN: Record<Step["state"], string> = {
  done: "Done",
  current: "Next",
  todo: "Not yet",
  cancelled: "Done",
};

function Tick() {
  return (
    <svg viewBox="0 0 16 16" className="size-3.5" fill="none" aria-hidden="true">
      <path
        d="M3.5 8.5l3 3 6-7"
        stroke="currentColor"
        strokeWidth="2.5"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}
