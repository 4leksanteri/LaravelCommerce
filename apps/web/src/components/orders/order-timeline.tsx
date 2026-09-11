import type { Order } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";
import type { OrderReader } from "@/lib/orders/status";
import { cn } from "@/lib/utils";

/**
 * What has happened to an order and what it is waiting for, told to whichever
 * side is reading.
 *
 * Read off the order's own timestamps: a step with a date has happened, the
 * first one without a date is what the order is waiting for, and the rest have
 * not happened yet. Nothing here decides what anybody may do. The buttons are
 * the actions components, drawn from the API's `can_*` answers.
 *
 * **The same order reads differently to each side.** Its buyer is told it is
 * waiting for the shop to send it; the shop is told it is waiting for them.
 * `reader` says which, and `counterpart` is the other side's name - the shop's
 * for a buyer, the buyer's for a shop. Both resources carry the same dates and
 * the same record of who ended it, so one timeline serves both (ADR 0036).
 *
 * **It says who, from what the API recorded** (ADR 0035). An order that ended
 * before anything recorded who says only what happened, rather than guessing.
 *
 * The design export's timeline has "Delivered" and a courier's tracking number.
 * Nothing records either - shipping is a `shipped_at` and no more - so there is
 * no step for them.
 */
type TimelineOrder = Pick<
  Order,
  | "status"
  | "placed_at"
  | "accepted_at"
  | "shipped_at"
  | "completed_at"
  | "cancelled_at"
  | "cancelled_by"
  | "cancellation_reason"
  | "completed_by"
  | "auto_complete_at"
>;

type Props = { order: TimelineOrder; reader: OrderReader; counterpart: string };

type Milestone = "placed" | "accepted" | "sent" | "completed";

type Step = {
  title: string;
  at: string | null;
  note: string | null;
  state: "done" | "current" | "todo" | "cancelled";
};

export function OrderTimeline(props: Props) {
  const steps = stepsOf(props);

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

function stepsOf({ order, reader, counterpart }: Props): Step[] {
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
        note: cancellation(order, reader, counterpart),
        state: "cancelled",
      },
    ];
  }

  const next = milestones.findIndex((milestone) => milestone.at === null);

  return milestones.map((milestone, index): Step => ({
    title: milestone.title,
    at: milestone.at,
    note:
      index === next
        ? waitingFor(milestone.key, order, reader, counterpart)
        : milestone.key === "completed" && milestone.at !== null
          ? completion(order, reader, counterpart)
          : null,
    state: milestone.at !== null ? "done" : index === next ? "current" : "todo",
  }));
}

function waitingFor(
  milestone: Milestone,
  order: TimelineOrder,
  reader: OrderReader,
  counterpart: string,
): string | null {
  const deadline = order.auto_complete_at ? formatDate(order.auto_complete_at) : null;

  switch (milestone) {
    case "accepted":
      return reader === "shop"
        ? "Waiting for you to accept it."
        : `Waiting for ${counterpart} to accept it.`;
    case "sent":
      return reader === "shop"
        ? "Waiting for you to send it."
        : `Waiting for ${counterpart} to send it.`;
    case "completed":
      if (reader === "shop") {
        return deadline
          ? `Waiting for ${counterpart} to confirm it arrived. If they do not, it completes on its own on ${deadline}.`
          : `Waiting for ${counterpart} to confirm it arrived.`;
      }

      return deadline
        ? `Confirm it arrived once you have checked it over. If you do not, it completes on its own on ${deadline}.`
        : "Confirm it arrived once you have checked it over.";
    case "placed":
      return null;
    default: {
      const unhandled: never = milestone;

      return unhandled;
    }
  }
}

/** Who called it off, and the shop's reason if the shop did. */
function cancellation(order: TimelineOrder, reader: OrderReader, counterpart: string): string {
  const reason = order.cancellation_reason;

  switch (order.cancelled_by) {
    case "buyer":
      return reader === "buyer" ? "You cancelled it." : `${counterpart} cancelled it.`;
    case "seller":
      if (reader === "shop") {
        return reason ? `You cancelled it. Your reason: ${reason}` : "You cancelled it.";
      }

      return reason
        ? `${counterpart} cancelled it. Their reason: ${reason}`
        : `${counterpart} cancelled it.`;
    case "deadline":
      return reader === "buyer"
        ? `${counterpart} did not accept it in time, so it was cancelled.`
        : "It was not accepted in time, so it was cancelled.";
    case null:
      return "Nothing more will happen to this order.";
    default: {
      const unhandled: never = order.cancelled_by;

      return unhandled;
    }
  }
}

/**
 * Whether the buyer confirmed it or its deadline passed. A shop never completes
 * an order, and the database refuses one that says it did, so that case and an
 * order from before anything recorded who both say nothing.
 */
function completion(order: TimelineOrder, reader: OrderReader, counterpart: string): string | null {
  switch (order.completed_by) {
    case "buyer":
      return reader === "buyer"
        ? "You confirmed it arrived."
        : `${counterpart} confirmed it arrived.`;
    case "deadline":
      return "It completed on its own when its deadline passed.";
    case "seller":
    case null:
      return null;
    default: {
      const unhandled: never = order.completed_by;

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
