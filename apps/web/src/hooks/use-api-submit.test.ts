import { act, renderHook } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { ApiError } from "@/lib/api/errors";

import { useApiSubmit } from "./use-api-submit";

/**
 * The rule under test is `apps/web/CLAUDE.md` section 9: never collapse the
 * statuses into one generic failure. So these assert that the refusals come
 * out *different from one another*, rather than pinning each sentence - the
 * wording is the page's to improve, the distinction is not.
 */

beforeEach(() => {
  // The hook logs what it hides from the page. That is correct, and noise here.
  vi.spyOn(console, "error").mockImplementation(() => {});
});

async function refusalFor(error: unknown) {
  const { result } = renderHook(() => useApiSubmit());

  await act(async () => {
    await result.current.submit(async () => {
      throw error;
    });
  });

  return result.current;
}

describe("useApiSubmit", () => {
  it("puts a 422's messages beside their fields, and says nothing at the top", async () => {
    const outcome = await refusalFor(
      new ApiError(422, {
        message: "Invalid.",
        errors: { email: ["Taken."], password: ["Too short.", "Leaked."] },
      }),
    );

    expect(outcome.fieldErrors).toEqual({ email: ["Taken."], password: ["Too short.", "Leaked."] });
    expect(outcome.failure).toBeNull();
  });

  it("gives 401, 403, 419 and 429 each its own message, none of them the generic one", async () => {
    // One at a time. Rendering several hooks inside overlapping act() scopes is
    // not something React supports, and it fails on the test, not the hook.
    const messages: Array<string | null> = [];

    for (const status of [401, 403, 419, 429]) {
      messages.push((await refusalFor(new ApiError(status, null))).failure);
    }

    const generic = (await refusalFor(new ApiError(500, null))).failure;

    expect(messages.every((message) => typeof message === "string" && message.length > 0)).toBe(
      true,
    );
    expect(new Set(messages).size).toBe(4);
    expect(messages).not.toContain(generic);
  });

  it("tells a network failure apart from a refusal", async () => {
    const offline = (await refusalFor(new TypeError("Failed to fetch"))).failure;
    const refused = (await refusalFor(new ApiError(500, null))).failure;

    expect(offline).not.toBe(refused);
  });

  it("is pending exactly while the request is in flight", async () => {
    const { result } = renderHook(() => useApiSubmit());
    let finish: () => void = () => {};

    let submission: Promise<void> = Promise.resolve();
    act(() => {
      submission = result.current.submit(() => new Promise<void>((resolve) => (finish = resolve)));
    });

    expect(result.current.pending).toBe(true);

    await act(async () => {
      finish();
      await submission;
    });

    expect(result.current.pending).toBe(false);
  });

  it("clears the last refusal when somebody tries again", async () => {
    const { result } = renderHook(() => useApiSubmit());

    await act(async () => {
      await result.current.submit(async () => {
        throw new ApiError(422, { errors: { email: ["Taken."] } });
      });
    });
    await act(async () => {
      await result.current.submit(async () => {});
    });

    expect(result.current.fieldErrors).toEqual({});
    expect(result.current.failure).toBeNull();
  });
});
