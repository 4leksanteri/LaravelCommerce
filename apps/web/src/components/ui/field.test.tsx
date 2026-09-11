import { render, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";

import { Field } from "./field";

/**
 * Field owns the wiring that makes a 422 usable without seeing it: the input is
 * marked invalid, and points at the text explaining why. That is the part that
 * gets done on three fields out of five when nothing owns it (ADR 0023).
 */
function describedBy(input: HTMLElement): string {
  return (input.getAttribute("aria-describedby") ?? "")
    .split(" ")
    .filter(Boolean)
    .map((id) => document.getElementById(id)?.textContent ?? `<missing #${id}>`)
    .join(" | ");
}

describe("Field", () => {
  it("labels its input", () => {
    render(<Field label="Email" name="email" />);

    expect(screen.getByLabelText("Email")).toHaveAttribute("name", "email");
  });

  it("marks the input invalid and points it at every message Laravel sent", () => {
    render(
      <Field label="Password" name="password" errors={["Too short.", "Found in a breach."]} />,
    );

    const input = screen.getByLabelText("Password");

    expect(input).toHaveAttribute("aria-invalid", "true");
    expect(describedBy(input)).toBe("Too short.Found in a breach.");
    expect(screen.getByText("Too short.")).toBeVisible();
    expect(screen.getByText("Found in a breach.")).toBeVisible();
  });

  it("points at the hint and the messages together when there are both", () => {
    render(
      <Field
        label="Password"
        name="password"
        hint="At least 12 characters."
        errors={["Too short."]}
      />,
    );

    expect(describedBy(screen.getByLabelText("Password"))).toBe(
      "At least 12 characters. | Too short.",
    );
  });

  it("is not invalid, and describes nothing it does not have, when there is nothing to say", () => {
    render(<Field label="Email" name="email" errors={[]} />);

    const input = screen.getByLabelText("Email");

    expect(input).not.toHaveAttribute("aria-invalid");
    expect(input).not.toHaveAttribute("aria-describedby");
  });

  it("gives two fields on one page different ids", () => {
    render(
      <>
        <Field label="Password" name="password" errors={["One."]} />
        <Field label="Confirm password" name="password_confirmation" errors={["Two."]} />
      </>,
    );

    expect(describedBy(screen.getByLabelText("Password"))).toBe("One.");
    expect(describedBy(screen.getByLabelText("Confirm password"))).toBe("Two.");
  });
});
