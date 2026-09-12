"use client";

import { useRouter } from "next/navigation";
import { useRef, useState, type FormEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";

/**
 * The photograph of a passport or identity card that Stripe asks for.
 *
 * **Multipart, and nothing is kept.** The file is streamed to Stripe and
 * deleted when the request ends (ADR 0031); no disk of ours ever holds it. The
 * content type is deliberately not set, so the browser writes it with its
 * boundary, as the listing photographs do.
 *
 * **Both sides go in one request**, because Stripe wants the pair together when
 * it wants a back at all - a passport has none, and the API's `back` is
 * optional for exactly that reason.
 *
 * What a document has to look like is Stripe's rule and the API's validation:
 * a file it will not take comes back as a 422 beside the input, and this
 * component checks nothing itself.
 */
export function PayoutIdentityDocument() {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [chosen, setChosen] = useState(false);
  const form = useRef<HTMLFormElement>(null);
  const front = useRef<HTMLInputElement>(null);
  const back = useRef<HTMLInputElement>(null);

  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    /*
     * Built from the inputs' own files rather than from `new FormData(form)`.
     *
     * An untouched file input still serialises into one, as an empty part the
     * API would read as a file that is not one. Filtering those back out means
     * asking whether a value is a `File`, and that is a question about which
     * realm built it rather than about what somebody chose - the first draft
     * asked it and deleted a real file. A FileList is the direct question.
     */
    const fields = new FormData();
    const chosenFront = front.current?.files?.[0];
    const chosenBack = back.current?.files?.[0];

    if (chosenFront) {
      fields.set("front", chosenFront);
    }

    if (chosenBack) {
      fields.set("back", chosenBack);
    }

    await submit(async () => {
      await apiFetch("/seller/payout-account/identity-document", {
        method: "POST",
        body: fields,
      });

      form.current?.reset();
      setChosen(false);
      router.refresh();
    });
  }

  return (
    <form
      ref={form}
      onSubmit={upload}
      aria-label="Identity document"
      className="space-y-4"
      noValidate
      onChange={() => setChosen(true)}
    >
      <div className="space-y-1.5">
        <label htmlFor="document-front" className="text-foreground block text-sm font-medium">
          The front
        </label>
        <input
          ref={front}
          id="document-front"
          name="front"
          type="file"
          accept="image/jpeg,image/png,application/pdf"
          required
          aria-describedby="document-front-hint"
          className="text-muted-foreground file:bg-secondary file:text-secondary-foreground hover:file:bg-secondary/80 block w-full text-sm file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:px-3 file:py-2 file:text-sm file:font-medium"
        />
        <p id="document-front-hint" className="text-muted-foreground text-xs">
          A photograph or scan, as JPEG, PNG or PDF, up to 10MB.
        </p>
        {fieldErrors.front?.map((message) => (
          <p key={message} className="text-destructive text-xs">
            {message}
          </p>
        ))}
      </div>

      <div className="space-y-1.5">
        <label htmlFor="document-back" className="text-foreground block text-sm font-medium">
          The back
        </label>
        <input
          ref={back}
          id="document-back"
          name="back"
          type="file"
          accept="image/jpeg,image/png,application/pdf"
          aria-describedby="document-back-hint"
          className="text-muted-foreground file:bg-secondary file:text-secondary-foreground hover:file:bg-secondary/80 block w-full text-sm file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:px-3 file:py-2 file:text-sm file:font-medium"
        />
        <p id="document-back-hint" className="text-muted-foreground text-xs">
          Only if your document has one. A passport does not.
        </p>
        {fieldErrors.back?.map((message) => (
          <p key={message} className="text-destructive text-xs">
            {message}
          </p>
        ))}
      </div>

      <Button type="submit" disabled={pending || !chosen}>
        {pending ? "Sending to Stripe..." : "Send the document"}
      </Button>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </form>
  );
}
