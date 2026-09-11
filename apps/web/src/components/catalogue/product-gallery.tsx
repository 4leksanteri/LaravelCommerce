"use client";

import Image from "next/image";
import { useState } from "react";

import type { ProductImage } from "@/lib/api/types";
import { cn } from "@/lib/utils";

/**
 * A listing's photographs: one large, the rest as thumbnails to switch to.
 *
 * The one client component on the product page that is there for looks. The
 * first photograph is rendered on the server like everything else, so the page
 * is complete before any JavaScript arrives; switching is the only thing that
 * needs the browser.
 *
 * `object-contain` in the large frame rather than `cover`. A card crops to a
 * square because it is one of many; here the whole photograph is the point, and
 * cropping the corner of a camera off its own listing hides the thing a buyer
 * came to inspect.
 *
 * Thumbnails carry an empty alt inside a labelled button: the button says what
 * it does, and a screen reader does not need the same photograph described
 * twice.
 */
export function ProductGallery({ images, name }: { images: ProductImage[]; name: string }) {
  const [selected, setSelected] = useState(0);

  if (images.length === 0) {
    return (
      <div className="bg-muted border-border text-muted-foreground flex aspect-[4/3] items-center justify-center rounded-lg border text-sm">
        No photograph yet
      </div>
    );
  }

  const shown = images[Math.min(selected, images.length - 1)];

  return (
    <div className="space-y-3">
      <div className="bg-card border-border relative aspect-[4/3] overflow-hidden rounded-lg border">
        <Image
          src={shown.url}
          // The seller's words where they wrote some, and the listing's name
          // where they did not - never empty, which would call it decorative.
          alt={shown.alt_text ?? name}
          fill
          sizes="(min-width: 1024px) 50vw, 100vw"
          className="object-contain"
        />
      </div>

      {images.length > 1 ? (
        <ul className="flex flex-wrap gap-2" aria-label="Photographs">
          {images.map((image, index) => (
            <li key={image.id}>
              <button
                type="button"
                onClick={() => setSelected(index)}
                aria-label={`Show photograph ${index + 1} of ${images.length}`}
                aria-current={index === selected ? "true" : undefined}
                className={cn(
                  "bg-card focus-visible:ring-ring relative block size-16 overflow-hidden rounded-md border outline-none focus-visible:ring-2 focus-visible:ring-offset-2",
                  index === selected ? "border-primary ring-primary ring-1" : "border-border",
                )}
              >
                <Image src={image.url} alt="" fill sizes="64px" className="object-cover" />
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
