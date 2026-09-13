"use client";

import { useRouter } from "next/navigation";
import { useEffect, useRef, useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { FieldFrame } from "@/components/ui/field";
import { Textarea } from "@/components/ui/textarea";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { OrderMessage, OrderParty } from "@/lib/api/types";
import { formatDate } from "@/lib/dates";

/**
 * One order's conversation, from whichever side is reading it (ADR 0050).
 *
 * **The same component serves both parties**, because there is one thread and
 * the order is it. What differs is `viewer`, which is the side this page
 * belongs to - `/account/orders/...` is the buyer's and `/seller/orders/...` is
 * the shop's. That is a routing fact this application already holds, which is
 * why the API publishes `sender` as a side rather than "yours" (ADR 0050), the
 * way `cancelled_by` is published and `order-timeline` reads it.
 *
 * **There is no state in which the form disappears.** A cancelled or completed
 * order can still be talked about, which is the decision the domain rests on: a
 * parcel that never came is exactly when two people need to reach each other.
 * So nothing here asks the order's status before drawing the form.
 *
 * **Reading is a write, and says so.** Opening the page marks the other side's
 * messages read through an endpoint of its own rather than as something the
 * fetch does on the way past - a `GET` does not change anything. It runs once,
 * only when something is actually waiting, and the redraw that follows brings
 * the count back as zero, so it does not run again.
 */
type Props = {
  messages: OrderMessage[];
  /** Which side this page belongs to. Not read from the messages. */
  viewer: OrderParty;
  /** The API's address for the order: `/orders/X` or `/seller/orders/X`. */
  endpoint: string;
  /** This page's own address, for sending somebody back after signing in. */
  page: string;
  /** What to call the other party. */
  counterpart: string;
  unread: number;
};

export function Conversation({ messages, viewer, endpoint, page, counterpart, unread }: Props) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [body, setBody] = useState("");
  const marked = useRef(false);

  useEffect(() => {
    if (unread === 0 || marked.current) {
      return;
    }

    // Once per mount. The ref guards React's double-invoke in development as
    // well as the redraw below, which arrives with `unread` at zero anyway.
    marked.current = true;

    apiFetch(`${endpoint}/messages/read`, { method: "POST" })
      .then(() => router.refresh())
      .catch((error: unknown) => {
        // Nobody asked for this, so nothing is shown. Letting it be tried
        // again is the whole recovery: the badge simply stays until next time.
        marked.current = false;
        console.error("The messages could not be marked read.", error);
      });
  }, [unread, endpoint, router]);

  function send(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    return submit(async () => {
      try {
        await apiFetch(`${endpoint}/messages`, {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ body }),
        });

        setBody("");
        router.refresh();
      } catch (error) {
        if (error instanceof ApiError && error.isUnauthenticated) {
          router.push(`/login?next=${encodeURIComponent(page)}`);

          return;
        }

        throw error;
      }
    });
  }

  return (
    <div className="space-y-4">
      {messages.length === 0 ? (
        <p className="text-muted-foreground text-sm">Nothing has been said about this order yet.</p>
      ) : (
        <ol className="space-y-3">
          {messages.map((message) => {
            const mine = message.sender === viewer;

            return (
              <li
                key={message.id}
                className={
                  mine
                    ? "border-border bg-muted rounded-lg border p-3"
                    : "bg-card border-border rounded-lg border p-3"
                }
              >
                <p className="text-muted-foreground text-xs">
                  <span className="font-semibold">{mine ? "You" : counterpart}</span>
                  {message.sent_at ? `, ${formatDate(message.sent_at)}` : null}
                  {/*
                   * Only on your own messages, and only once it has happened.
                   * "Not read yet" on every unanswered message would be a line
                   * that exists to announce an absence.
                   */}
                  {mine && message.read_at ? " . Read" : null}
                </p>
                <p className="mt-1 text-sm leading-relaxed whitespace-pre-wrap">{message.body}</p>
              </li>
            );
          })}
        </ol>
      )}

      <form onSubmit={send} noValidate aria-label="Send a message" className="space-y-3">
        <FieldFrame
          label="Message"
          hint={`${counterpart} is told by email, and reads it on their own copy of this order.`}
          errors={fieldErrors.body}
        >
          {(control) => (
            <Textarea
              {...control}
              name="body"
              rows={3}
              maxLength={2000}
              value={body}
              onChange={(event) => setBody(event.target.value)}
            />
          )}
        </FieldFrame>

        <Button type="submit" size="sm" disabled={pending}>
          {pending ? "Sending..." : "Send"}
        </Button>
      </form>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
