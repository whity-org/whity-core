/**
 * A request the server refused (403) is not a failed load and not an empty
 * list: the person lacks access, and the screen says so in plain words
 * (`AccessDeniedPanel`) instead of "Failed to load" with Try again.
 */
export class ForbiddenError extends Error {
  constructor() {
    super('forbidden');
    this.name = 'ForbiddenError';
  }
}

export function isForbiddenError(error: unknown): boolean {
  return error instanceof ForbiddenError;
}

/** Throw `ForbiddenError` on a 403, else `Error(message)` on any other failure. */
export function throwUnlessOk(response: { ok: boolean; status: number }, message: string): void {
  if (response.status === 403) throw new ForbiddenError();
  if (!response.ok) throw new Error(message);
}
