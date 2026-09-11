import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { PillLink } from "./pill-link";

describe("PillLink", () => {
  it("links where it says", () => {
    render(
      <PillLink href="/categories/audio" active={false}>
        Audio
      </PillLink>,
    );

    expect(screen.getByRole("link", { name: "Audio" })).toHaveAttribute(
      "href",
      "/categories/audio",
    );
  });

  it("marks the chosen one as current in its set, not as the current page", () => {
    render(
      <PillLink href="/categories/audio" active>
        Audio
      </PillLink>,
    );

    expect(screen.getByRole("link", { name: "Audio" })).toHaveAttribute("aria-current", "true");
  });

  it("says nothing about the ones not chosen", () => {
    render(
      <PillLink href="/categories/audio" active={false}>
        Audio
      </PillLink>,
    );

    expect(screen.getByRole("link", { name: "Audio" })).not.toHaveAttribute("aria-current");
  });
});
