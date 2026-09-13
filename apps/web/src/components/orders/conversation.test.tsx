import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { apiFetch } from "@/lib/api/client";
import { ApiError } from "@/lib/api/errors";
import type { OrderMessage } from "@/lib/api/types";

import { Conversation } from "./conversation";

const router = { push: vi.fn(), refresh: vi.fn() };

vi.mock("next/navigation", () => ({ useRouter: () => router }));
vi.mock("@/lib/api/client", () => ({ apiFetch: vi.fn() }));

const request = vi.mocked(apiFetch);

const fromBuyer: OrderMessage = {
  id: 1,
  sender: "buyer",
  body: "Has this been posted yet?",
  sent_at: "2026-03-01T10:00:00+00:00",
  read_at: null,
};

const fromShop: OrderMessage = {
  id: 2,
  sender: "seller",
  body: "Going out this afternoon.",
  sent_at: "2026-03-01T12:00:00+00:00",
  read_at: null,
};

function conversation(props: Partial<React.ComponentProps<typeof Conversation>> = {}) {
  return (
    <Conversation
      messages={[fromBuyer, fromShop]}
      viewer="buyer"
      endpoint="/orders/K7M2QXV9RT"
      page="/account/orders/K7M2QXV9RT"
      counterpart="Second Hand Time"
      unread={0}
      {...props}
    />
  );
}

beforeEach(() => {
  router.push.mockReset();
  router.refresh.mockReset();
  request.mockReset();
  request.mockResolvedValue({ data: fromBuyer });
});

describe("Conversation", () => {
  /**
   * The same rows read from either end. `sender` is the side that wrote it, and
   * which side is reading is the page's own address - so the buyer's copy calls
   * their own messages "You" and the shop's copy calls the same ones by the
   * buyer's name (ADR 0050).
   */
  it("names each side from the point of view of whoever is reading", () => {
    render(conversation({ viewer: "buyer" }));

    const mine = screen.getByText(fromBuyer.body).closest("li");
    const theirs = screen.getByText(fromShop.body).closest("li");

    expect(mine).toHaveTextContent("You");
    expect(theirs).toHaveTextContent("Second Hand Time");
  });

  it("names them the other way round for the shop", () => {
    render(conversation({ viewer: "seller", counterpart: "Aino Virtanen" }));

    expect(screen.getByText(fromShop.body).closest("li")).toHaveTextContent("You");
    expect(screen.getByText(fromBuyer.body).closest("li")).toHaveTextContent("Aino Virtanen");
  });

  it("says so when nothing has been said", () => {
    render(conversation({ messages: [] }));

    expect(screen.getByText(/Nothing has been said/)).toBeVisible();
  });

  /**
   * There is no state in which two people stop being able to reach each other,
   * so the form is not drawn from the order's status at all - it is always
   * there (ADR 0050).
   */
  it("sends what was written, and clears the box", async () => {
    const user = userEvent.setup();

    render(conversation());
    await user.type(screen.getByLabelText("Message"), "Any news?");
    await user.click(screen.getByRole("button", { name: "Send" }));

    expect(request).toHaveBeenCalledWith(
      "/orders/K7M2QXV9RT/messages",
      expect.objectContaining({
        method: "POST",
        body: JSON.stringify({ body: "Any news?" }),
      }),
    );
    expect(router.refresh).toHaveBeenCalled();
    expect(screen.getByLabelText("Message")).toHaveValue("");
  });

  it("puts the API's refusal beside the box, and keeps what was typed", async () => {
    const message = "The body field is required.";
    request.mockRejectedValue(new ApiError(422, { message, errors: { body: [message] } }));
    const user = userEvent.setup();

    render(conversation());
    await user.type(screen.getByLabelText("Message"), "  ");
    await user.click(screen.getByRole("button", { name: "Send" }));

    expect(await screen.findByText(message)).toBeVisible();
    expect(screen.getByLabelText("Message")).toHaveAttribute("aria-invalid", "true");
  });

  it("sends somebody whose session ended to sign in, and back to the order", async () => {
    request.mockRejectedValue(new ApiError(401, { message: "Unauthenticated." }));
    const user = userEvent.setup();

    render(conversation());
    await user.type(screen.getByLabelText("Message"), "Any news?");
    await user.click(screen.getByRole("button", { name: "Send" }));

    await waitFor(() =>
      expect(router.push).toHaveBeenCalledWith("/login?next=%2Faccount%2Forders%2FK7M2QXV9RT"),
    );
  });

  // --- Marking it read -----------------------------------------------------

  /**
   * A read is a change, so it is a POST of its own rather than something the
   * fetch does on the way past (ADR 0050).
   */
  it("marks the other side's messages read when some are waiting", async () => {
    render(conversation({ unread: 2 }));

    await waitFor(() =>
      expect(request).toHaveBeenCalledWith("/orders/K7M2QXV9RT/messages/read", { method: "POST" }),
    );
    await waitFor(() => expect(router.refresh).toHaveBeenCalled());
  });

  it("marks nothing read when nothing is waiting", () => {
    render(conversation({ unread: 0 }));

    expect(request).not.toHaveBeenCalled();
  });

  /** Only on your own messages, and only once it has happened. */
  it("says when something you wrote has been read", () => {
    render(
      conversation({
        messages: [{ ...fromBuyer, read_at: "2026-03-01T13:00:00+00:00" }],
        viewer: "buyer",
      }),
    );

    expect(screen.getByText(fromBuyer.body).closest("li")).toHaveTextContent("Read");
  });

  it("says nothing about reading on a message the other side wrote", () => {
    render(conversation({ messages: [{ ...fromShop, read_at: null }], viewer: "buyer" }));

    expect(screen.getByText(fromShop.body).closest("li")).not.toHaveTextContent("Read");
  });
});
