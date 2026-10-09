/**
 * Frontend error utilities.
 *
 * Key rule: ONLY HTTP status 419 maps to "session_expired".
 * Network errors / missing status / 500 / all others → "errors.generic".
 */

/**
 * Attempt to extract a numeric HTTP status from an unknown error value.
 * Returns null when no status can be determined.
 */
export function getErrorStatus(error: unknown): number | null {
    if (error === null || error === undefined) return null;

    // Axios-style error: error.response.status
    if (
        typeof error === 'object' &&
        'response' in error &&
        error.response !== null &&
        typeof error.response === 'object' &&
        'status' in (error.response as object)
    ) {
        const status = (error.response as { status: unknown }).status;
        if (typeof status === 'number') return status;
    }

    // Plain object with a status field
    if (typeof error === 'object' && 'status' in error) {
        const status = (error as { status: unknown }).status;
        if (typeof status === 'number') return status;
    }

    return null;
}

/**
 * Return true only when the error is definitively an HTTP 419 (CSRF/session expired).
 * Never returns true for network errors or missing status codes.
 */
export function isSessionExpired(error: unknown): boolean {
    return getErrorStatus(error) === 419;
}

/**
 * Map an error to the correct i18n key and return the translated string via t().
 * ONLY status 419 maps to session_expired. All other statuses use their own key.
 * Network errors / undefined status → generic.
 */
export function getErrorMessage(error: unknown, t: (key: string) => string): string {
    const status = getErrorStatus(error);
    switch (status) {
        case 400: return t('errors.bad_request');
        case 401: return t('errors.unauthorized');
        case 403: return t('errors.forbidden');
        case 404: return t('errors.not_found');
        case 405: return t('errors.method_not_allowed');
        case 409: return t('errors.conflict');
        case 419: return t('errors.session_expired');
        case 422: return t('errors.validation_failed');
        case 429: return t('errors.too_many_requests');
        case 503: return t('errors.service_unavailable');
        default:  return t('errors.generic');
    }
}

/**
 * Helper to safely read the CSRF token from the page meta tag.
 * Use this for any native fetch() calls that send state-changing requests
 * (POST / PUT / PATCH / DELETE) outside of Inertia/axios.
 */
export function getCsrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta instanceof HTMLMetaElement ? meta.content : '';
}
