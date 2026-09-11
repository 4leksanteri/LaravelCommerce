import { render, screen } from "@testing-library/react";
import { usePathname } from "next/navigation";
import { describe, expect, it, vi } from "vitest";

import { SideNav, type SideNavItem } from "./side-nav";

vi.mock("next/navigation", () => ({ usePathname: vi.fn() }));

const pathname = vi.mocked(usePathname);

const ACCOUNT: SideNavItem[] = [
  { href: "/account", label: "Overview", exact: true },
  { href: "/account/orders", label: "Orders" },
];

const link = (name: string) => screen.getByRole("link", { name });

describe("SideNav", () => {
  it("marks the page on screen and nothing else", () => {
    pathname.mockReturnValue("/account/orders");

    render(<SideNav label="Your account" items={ACCOUNT} />);

    expect(link("Orders")).toHaveAttribute("aria-current", "page");
    expect(link("Overview")).not.toHaveAttribute("aria-current");
  });

  it("keeps a section marked on the pages beneath it", () => {
    pathname.mockReturnValue("/account/orders/K7M2QXV9RT");

    render(<SideNav label="Your account" items={ACCOUNT} />);

    expect(link("Orders")).toHaveAttribute("aria-current", "page");
  });

  /**
   * An overview's address begins every other address in its section, so it is
   * marked on its own page only - or it would be marked everywhere.
   */
  it("marks an overview on its own address only", () => {
    pathname.mockReturnValue("/account");

    render(<SideNav label="Your account" items={ACCOUNT} />);

    expect(link("Overview")).toHaveAttribute("aria-current", "page");
    expect(link("Orders")).not.toHaveAttribute("aria-current");
  });

  it("does not mark a link that only shares the start of a word", () => {
    pathname.mockReturnValue("/seller");

    render(<SideNav label="Selling" items={[{ href: "/sell", label: "Open a shop" }]} />);

    expect(link("Open a shop")).not.toHaveAttribute("aria-current");
  });
});
