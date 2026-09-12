"use client";

import Image from "next/image";
import { useRouter } from "next/navigation";
import { useRef, useState, type ChangeEvent } from "react";

import { Alert } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { useApiSubmit } from "@/hooks/use-api-submit";
import { apiFetch } from "@/lib/api/client";
import type { Product, ProductImage } from "@/lib/api/types";

/**
 * A listing's photographs: adding one, describing it, and removing it.
 *
 * **Multipart, through the same `apiFetch` as everything else.** The body is a
 * `FormData` and the content type is deliberately not set: the browser writes
 * it, with the boundary, and setting it by hand produces a body the API cannot
 * parse. CSRF and the session are handled where they always are (ADR 0003).
 *
 * **The API decides what an image is.** Type, size and the limit per listing
 * are its rules: a file it will not take comes back as a 422 beside the field,
 * and one photograph too many as a 409 with its own message. Nothing here
 * checks the bytes, because a check in the browser is advice rather than a
 * rule.
 *
 * **A draft's photograph is served against a signature that expires**
 * (ADR 0016), and `next/image` must not optimise those: the optimiser caches by
 * URL and would go on serving one after its signature died. They are rendered
 * from the signed URL itself instead.
 *
 * The alt text is the seller's description of the photograph, and it is saved
 * on its own because it is often written after the upload.
 */
export function ListingImages({ listing, limit }: { listing: Product; limit: number }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const chooser = useRef<HTMLInputElement>(null);

  async function upload(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0];

    if (!file) {
      return;
    }

    await submit(async () => {
      const body = new FormData();
      body.set("image", file);

      // One photograph too many is a 409, and a file the API will not take is a
      // 422. `useApiSubmit` classifies both: the first reaches `failure` as the
      // API's own sentence, the second lands beside the field.
      try {
        await apiFetch(`/seller/products/${listing.id}/images`, { method: "POST", body });

        router.refresh();
      } finally {
        // So the same file can be chosen again after a refusal.
        if (chooser.current) {
          chooser.current.value = "";
        }
      }
    });
  }

  return (
    <div className="space-y-3">
      {listing.images.length > 0 ? (
        <ul aria-label="Photographs" className="grid gap-3 sm:grid-cols-2">
          {listing.images.map((image) => (
            <li key={image.id} className="border-border space-y-2 rounded-lg border p-3">
              <Photograph listing={listing} image={image} />
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-muted-foreground text-sm">
          No photographs yet. A listing with none is a hard thing to sell.
        </p>
      )}

      <div className="space-y-1.5">
        <label
          htmlFor={`upload-${listing.id}`}
          className="text-foreground block text-sm font-medium"
        >
          Add a photograph
        </label>
        <input
          ref={chooser}
          id={`upload-${listing.id}`}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          disabled={pending || listing.images.length >= limit}
          onChange={upload}
          aria-describedby={`upload-hint-${listing.id}`}
          className="text-muted-foreground file:bg-secondary file:text-secondary-foreground hover:file:bg-secondary/80 block w-full text-sm file:mr-3 file:cursor-pointer file:rounded-md file:border-0 file:px-3 file:py-2 file:text-sm file:font-medium disabled:cursor-not-allowed disabled:opacity-60"
        />
        <p id={`upload-hint-${listing.id}`} className="text-muted-foreground text-xs">
          {listing.images.length >= limit
            ? `This listing has the most photographs it may have (${limit}).`
            : `JPEG, PNG or WebP. Up to ${limit} per listing, and each is stored as a WebP with its location data removed.`}
        </p>
        {fieldErrors.image?.map((message) => (
          <p key={message} className="text-destructive text-xs">
            {message}
          </p>
        ))}
      </div>

      {pending ? <p className="text-muted-foreground text-sm">Uploading...</p> : null}
      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}

function Photograph({ listing, image }: { listing: Product; image: ProductImage }) {
  const router = useRouter();
  const { pending, fieldErrors, failure, submit } = useApiSubmit();
  const [altText, setAltText] = useState(image.alt_text ?? "");
  const [saved, setSaved] = useState(false);

  async function describe() {
    setSaved(false);

    await submit(async () => {
      await apiFetch(`/seller/products/${listing.id}/images/${image.id}`, {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({ alt_text: altText === "" ? null : altText }),
      });

      setSaved(true);
      router.refresh();
    });
  }

  async function remove() {
    await submit(async () => {
      await apiFetch(`/seller/products/${listing.id}/images/${image.id}`, { method: "DELETE" });

      router.refresh();
    });
  }

  const described = image.alt_text ?? listing.name;

  return (
    <div className="space-y-2">
      <div className="bg-muted border-border relative aspect-[4/3] overflow-hidden rounded-md border">
        <Image
          src={image.url}
          alt={described}
          fill
          sizes="(min-width: 640px) 50vw, 100vw"
          unoptimized={image.url.includes("?")}
          className="object-contain"
        />
      </div>

      <div className="space-y-1.5">
        <label
          htmlFor={`alt-${image.id}`}
          className="text-muted-foreground block text-xs font-medium"
        >
          What is in the photograph
        </label>
        <input
          id={`alt-${image.id}`}
          value={altText}
          onChange={(event) => setAltText(event.target.value)}
          placeholder="Read out to anybody who cannot see it"
          className="border-input bg-card focus-visible:border-ring focus-visible:ring-ring/30 h-9 w-full rounded-md border px-3 text-sm outline-none focus-visible:ring-2"
        />
        {fieldErrors.alt_text?.map((message) => (
          <p key={message} className="text-destructive text-xs">
            {message}
          </p>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <Button size="sm" variant="secondary" disabled={pending} onClick={describe}>
          Save the description
        </Button>
        <Button size="sm" variant="ghost" disabled={pending} onClick={remove}>
          Remove
        </Button>
        {saved ? <span className="text-positive text-xs font-medium">Saved.</span> : null}
      </div>

      {failure ? <Alert tone="danger">{failure}</Alert> : null}
    </div>
  );
}
