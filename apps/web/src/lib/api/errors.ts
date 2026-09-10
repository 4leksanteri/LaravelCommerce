/**
 * A refusal from the API, carried rather than swallowed.
 *
 * The status is part of the contract and callers branch on it. Two in
 * particular are not interchangeable:
 *
 *   401  the session is gone. Clear local state and send the person to sign in.
 *   403  the session is fine and the action is not allowed. Explain it; do not
 *        sign anybody out.
 *
 * Answering one where the other is meant makes a real interaction wrong, so
 * never collapse them into a generic failure.
 */
export class ApiError extends Error {
  constructor(
    readonly status: number,
    readonly body: unknown,
    message?: string,
  ) {
    super(message ?? `The API responded ${status}.`);
    this.name = "ApiError";
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get isForbidden(): boolean {
    return this.status === 403;
  }

  /**
   * Laravel answers 422 with `{ message, errors: { field: [messages] } }` for
   * a failed validation. Field errors belong beside the field that caused
   * them, not in a banner at the top of the form.
   */
  get validationErrors(): Record<string, string[]> | null {
    if (this.status !== 422) return null;

    const errors = (this.body as { errors?: unknown })?.errors;

    return typeof errors === "object" && errors !== null
      ? (errors as Record<string, string[]>)
      : null;
  }
}

/** Reads a response body as JSON where it is JSON, and as text where it is not. */
export async function readBody(response: Response): Promise<unknown> {
  const contentType = response.headers.get("content-type") ?? "";

  if (contentType.includes("application/json")) {
    return response.json().catch(() => null);
  }

  return response.text().catch(() => null);
}
