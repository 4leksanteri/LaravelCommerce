import { describe, expect, it } from "vitest";

import { buyerPaymentState, paymentLabel, shopPaymentState } from "./payment";

/**
 * Which words go with an order's money (ADR 0043).
 *
 * Worth testing because two of these are easy to get backwards, and both are
 * wrong in a way somebody would believe: a refunded order whose charge still
 * says "succeeded", and a shop being told it has money the platform is still
 * holding.
 */
describe("what the buyer is told", () => {
  it("says refunded even though the charge succeeded", () => {
    const state = buyerPaymentState({
      payment_status: "succeeded",
      paid_at: "2026-03-01T10:00:00+00:00",
      refunded_at: "2026-03-04T09:00:00+00:00",
    });

    expect(state).toBe("refunded");
    expect(paymentLabel(state)).toBe("Refunded");
  });

  it("says paid once the charge went through", () => {
    expect(
      buyerPaymentState({
        payment_status: "succeeded",
        paid_at: "2026-03-01T10:00:00+00:00",
        refunded_at: null,
      }),
    ).toBe("paid");
  });

  it("says nothing has been paid when no intent exists yet", () => {
    const state = buyerPaymentState({ payment_status: null, paid_at: null, refunded_at: null });

    expect(state).toBe("unpaid");
    expect(paymentLabel(state)).toBe("Not paid yet");
  });

  it("tells a buyer their card was declined rather than that nothing happened", () => {
    const state = buyerPaymentState({ payment_status: "failed", paid_at: null, refunded_at: null });

    expect(state).toBe("declined");
    expect(paymentLabel(state)).toBe("Card declined");
  });

  it("asks a buyer to finish a payment that needs authenticating", () => {
    expect(
      buyerPaymentState({ payment_status: "requires_action", paid_at: null, refunded_at: null }),
    ).toBe("confirming");
  });
});

describe("what the shop is told", () => {
  /** The money is on the platform, not with the shop, until completion. */
  it("says a paid order is held rather than paid out", () => {
    const state = shopPaymentState({
      paid_at: "2026-03-01T10:00:00+00:00",
      transferred_at: null,
      reversed_at: null,
      refunded_at: null,
    });

    expect(state).toBe("paid");
    expect(paymentLabel(state, "shop")).toBe("Paid, held");
  });

  it("says paid out once the transfer has gone", () => {
    const state = shopPaymentState({
      paid_at: "2026-03-01T10:00:00+00:00",
      transferred_at: "2026-03-10T09:00:00+00:00",
      reversed_at: null,
      refunded_at: null,
    });

    expect(state).toBe("transferred");
    expect(paymentLabel(state, "shop")).toBe("Paid out to you");
  });

  it("says refunded, whatever else happened", () => {
    expect(
      shopPaymentState({
        paid_at: "2026-03-01T10:00:00+00:00",
        transferred_at: null,
        reversed_at: null,
        refunded_at: "2026-03-04T09:00:00+00:00",
      }),
    ).toBe("refunded");
  });

  /**
   * **The defect ADR 0061 would have shipped without this.**
   *
   * A reversal leaves `transferred_at` set deliberately, so reading the
   * transfer first told a shop "Paid out to you" about money already debited
   * from its account. This is the window between the reversal and the refund,
   * which is real: the pair can be interrupted and finished by a later
   * `payments:settle` run.
   */
  it("does not call money paid out after it has been taken back", () => {
    const state = shopPaymentState({
      paid_at: "2026-03-01T10:00:00+00:00",
      transferred_at: "2026-03-10T09:00:00+00:00",
      reversed_at: "2026-03-20T09:00:00+00:00",
      refunded_at: null,
    });

    expect(state).toBe("reversed");
    expect(paymentLabel(state, "shop")).toBe("Taken back");
  });

  /** And once the buyer has it back, the shop still reads what happened to it. */
  it("still says taken back once the refund has landed", () => {
    expect(
      shopPaymentState({
        paid_at: "2026-03-01T10:00:00+00:00",
        transferred_at: "2026-03-10T09:00:00+00:00",
        reversed_at: "2026-03-20T09:00:00+00:00",
        refunded_at: "2026-03-20T09:00:01+00:00",
      }),
    ).toBe("reversed");
  });
});

describe("the same state, two readers", () => {
  it("reads as paid to a buyer and as held to the shop", () => {
    expect(paymentLabel("paid", "buyer")).toBe("Paid");
    expect(paymentLabel("paid", "shop")).toBe("Paid, held");
  });

  /** A transfer is the shop's news. To the buyer it is simply paid. */
  it("does not tell a buyer about a transfer", () => {
    expect(paymentLabel("transferred", "buyer")).toBe("Paid");
  });
});
