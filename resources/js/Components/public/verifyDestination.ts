/**
 * Route a scanned or pasted value to the page that can handle it.
 *
 * Two different public QR codes exist and a visitor does not know which one
 * they are holding:
 *
 *  - ID card QR  -> /id-checker/{uuid}          (dashed UUID)
 *  - Feedback QR -> /service-feedback/{token}   (64 hex characters)
 *
 * Sending a feedback QR to the ID checker would fail with a confusing "card not
 * found", so the shape of the value decides the destination. A full URL is
 * handled too, since people paste links as often as they scan them.
 *
 * Used by /verify.
 */
export function resolveVerifyDestination(raw: string): string | null {
    const value = raw.trim();

    if (value === '') {
        return null;
    }

    // An already-complete link to either public page: follow its own path.
    const pathMatch = value.match(/\/(id-checker|service-feedback)\/([^/?#\s]+)/i);

    if (pathMatch) {
        return `/${pathMatch[1].toLowerCase()}/${pathMatch[2]}`;
    }

    // A bare 64-character hex string is a feedback token.
    if (/^[0-9a-f]{64}$/i.test(value)) {
        return `/service-feedback/${value}`;
    }

    // A bare UUID is a card reference.
    const uuidMatch = value.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i);

    if (uuidMatch) {
        return `/id-checker/${uuidMatch[0]}`;
    }

    // Unrecognised shape: let the ID checker answer, which fails uniformly and
    // reveals nothing about which references exist.
    return `/id-checker/${encodeURIComponent(value)}`;
}
