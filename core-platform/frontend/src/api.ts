// The one door to the Core API: bearer token, JSON in and out, Laravel errors
// turned into ApiError so forms can show the field messages.

const BASE = `${import.meta.env.VITE_API_BASE_URL ?? ''}/api`;
const TOKEN_KEY = 'core.token';

export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

export type Page<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type Query = Record<string, string | number | boolean | undefined>;

export function getToken(): string | null {
    try {
        return localStorage.getItem(TOKEN_KEY);
    } catch {
        return null;
    }
}

export function setToken(token: string | null): void {
    try {
        if (token === null) localStorage.removeItem(TOKEN_KEY);
        else localStorage.setItem(TOKEN_KEY, token);
    } catch {
        // Private windows may refuse storage; the session then lasts one page load.
    }
}

let onUnauthorized: () => void = () => {};

export function setUnauthorizedHandler(handler: () => void): void {
    onUnauthorized = handler;
}

export async function api<T>(path: string, options: { method?: string; body?: unknown; query?: Query } = {}): Promise<T> {
    const url = new URL(BASE + path, window.location.origin);
    for (const [key, value] of Object.entries(options.query ?? {})) {
        if (value !== undefined && value !== '' && value !== false) url.searchParams.set(key, String(value));
    }

    const headers: Record<string, string> = { Accept: 'application/json' };
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';
    const token = getToken();
    if (token) headers.Authorization = `Bearer ${token}`;

    const response = await fetch(url, {
        method: options.method ?? 'GET',
        headers,
        body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const data = response.status === 204 ? null : await response.json().catch(() => null);

    if (!response.ok) {
        if (response.status === 401 && token) onUnauthorized();
        throw new ApiError(response.status, data?.message ?? response.statusText, data?.errors ?? {});
    }

    return data as T;
}

export function firstError(error: unknown): string | null {
    if (!(error instanceof ApiError)) return error instanceof Error ? error.message : null;
    const fields = Object.values(error.errors);

    return fields.length > 0 ? fields[0][0] : error.message;
}
