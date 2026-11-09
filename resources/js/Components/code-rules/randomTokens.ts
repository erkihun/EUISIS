/**
 * Tokens that resolve to a random value.
 *
 * Mirrors CodeFormatTokenResolver::RANDOM_TOKENS. A format using one of these
 * cannot rely on a sequence to stay unique, so the generator retries on
 * collision and the token is never offered as a sequence scope.
 */
export const RANDOM_TOKENS = ['RAND_6', 'RAND_8'] as const;

/** Whether a format produces a random value that may collide. */
export function usesRandomToken(format: string): boolean {
    return RANDOM_TOKENS.some((token) => format.includes(`{${token}}`));
}
