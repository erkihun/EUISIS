/**
 * The single source of truth for status colouring.
 *
 * Previously this map spent eleven different hues — emerald, yellow, orange,
 * blue, sky, indigo, purple, red, rose, gray — on statuses whose differences
 * were not worth a colour. "Verified" in sky and "Approved" in indigo sit two
 * rows apart in a table and read as two unrelated kinds of thing, when both
 * simply mean *this went through*. That is the "rainbow dashboard" effect, and
 * it costs real legibility: when eleven colours are in play, none of them
 * carries a signal.
 *
 * There are now five tones, and a status earns a colour only by answering
 * "what should the operator DO about this?":
 *
 *   neutral  — nothing to do; a resting or historical state
 *   success  — the good terminal state; nothing to do
 *   info     — in flight, moving through a workflow on its own
 *   warning  — someone needs to act, but nothing is broken
 *   danger   — blocked, refused, or lost; needs attention now
 *
 * Colour is never the only carrier: every badge renders its label as text, so
 * the tone is redundant reinforcement rather than the message itself.
 */

export type StatusTone = 'neutral' | 'success' | 'info' | 'warning' | 'danger';

export type StatusStyle = {
    label: string;
    tone: StatusTone;
    className: string;
};

/*
 * Low-saturation fills with a hairline border, rather than the saturated
 * chips used before. At the density of an admin table — twenty badges in one
 * viewport — saturated fills fight the data for attention; a tinted surface
 * with a defined edge stays readable without shouting.
 */
export const toneClasses: Record<StatusTone, string> = {
    neutral:
        'bg-gray-50 text-gray-700 ring-1 ring-inset ring-gray-200 ' +
        'dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700',
    success:
        'bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200 ' +
        'dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
    info:
        'bg-[color:var(--color-primary-50)] text-[color:var(--color-primary-800)] ring-1 ring-inset ring-[color:var(--color-primary-200)] ' +
        'dark:bg-[color:var(--color-primary-950)] dark:text-[color:var(--color-primary-200)] dark:ring-[color:var(--color-primary-800)]',
    warning:
        'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200 ' +
        'dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',
    danger:
        'bg-red-50 text-red-800 ring-1 ring-inset ring-red-200 ' +
        'dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
};

/**
 * Status key → [English label, tone].
 *
 * The label is a fallback only. Callers that have a translated label pass it
 * via the `label` prop on StatusBadge; this map keeps the many call sites that
 * render a raw enum value from showing a bare snake_case string.
 */
const STATUS_MAP: Record<string, [label: string, tone: StatusTone]> = {
    /* ── Resting / historical ─────────────────────────────── */
    inactive: ['Inactive', 'neutral'],
    retired: ['Retired', 'neutral'],
    replaced: ['Replaced', 'neutral'],
    expired: ['Expired', 'neutral'],
    archived: ['Archived', 'neutral'],
    closed: ['Closed', 'neutral'],
    vacant: ['Vacant', 'neutral'],
    draft: ['Draft', 'neutral'],
    cancelled: ['Cancelled', 'neutral'],
    canceled: ['Canceled', 'neutral'],
    unknown: ['Unknown', 'neutral'],
    // Daily activity: exempt or untracked days need nothing from anyone.
    weekend: ['Non-working day', 'neutral'],
    public_holiday: ['Public holiday', 'neutral'],
    leave: ['Leave', 'neutral'],
    not_tracked: ['Not tracked', 'neutral'],
    not_employed: ['Not employed', 'neutral'],
    not_assigned: ['Not assigned', 'neutral'],
    future: ['Future', 'neutral'],
    carried_forward: ['Carried forward', 'neutral'],

    /* ── Good terminal states ─────────────────────────────── */
    active: ['Active', 'success'],
    issued: ['Issued', 'success'],
    published: ['Published', 'success'],
    allowed: ['Allowed', 'success'],
    approved: ['Approved', 'success'],
    verified: ['Verified', 'success'],
    completed: ['Completed', 'success'],
    resolved: ['Resolved', 'success'],
    occupied: ['Occupied', 'success'],
    provisioned: ['Provisioned', 'success'],
    success: ['Success', 'success'],

    /* ── Moving through a workflow ────────────────────────── */
    pending: ['Pending', 'info'],
    submitted: ['Submitted', 'info'],
    under_review: ['Under Review', 'info'],
    in_progress: ['In Progress', 'info'],
    processing: ['Processing', 'info'],
    transferred: ['Transferred', 'info'],
    printed: ['Printed', 'info'],
    queued: ['Queued', 'info'],
    open: ['Open', 'info'],
    scheduled: ['Scheduled', 'info'],
    resubmitted: ['Resubmitted', 'info'],
    required: ['Required', 'info'],

    /* ── Needs a person to act ────────────────────────────── */
    suspended: ['Suspended', 'warning'],
    paused: ['Paused', 'warning'],
    on_hold: ['On Hold', 'warning'],
    exhausted: ['Exhausted', 'warning'],
    overdue: ['Overdue', 'warning'],
    escalated: ['Escalated', 'warning'],
    damaged: ['Damaged', 'warning'],
    warning: ['Warning', 'warning'],
    expiring_soon: ['Expiring Soon', 'warning'],
    returned_for_correction: ['Returned for correction', 'warning'],
    returned: ['Returned', 'warning'],

    /* ── Blocked / refused / lost ─────────────────────────── */
    revoked: ['Revoked', 'danger'],
    lost: ['Lost', 'danger'],
    denied: ['Denied', 'danger'],
    rejected: ['Rejected', 'danger'],
    dissolved: ['Dissolved', 'danger'],
    failed: ['Failed', 'danger'],
    blocked: ['Blocked', 'danger'],
    missing: ['Missing', 'danger'],
    error: ['Error', 'danger'],
};

const FALLBACK_TONE: StatusTone = 'neutral';

/**
 * Turns a raw status value into a label and a tone.
 *
 * Unrecognised values fall back to neutral rather than to a guess: an unknown
 * status is precisely the case where inventing a colour would mislead.
 */
export function getStatusStyle(status: string): StatusStyle {
    const normalized = (status ?? '').toLowerCase().replace(/[\s-]+/g, '_');
    const found = STATUS_MAP[normalized];

    if (!found) {
        return {
            label: status,
            tone: FALLBACK_TONE,
            className: toneClasses[FALLBACK_TONE],
        };
    }

    const [label, tone] = found;

    return { label: label || status, tone, className: toneClasses[tone] };
}
