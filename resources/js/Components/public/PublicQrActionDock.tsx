import { Link } from '@inertiajs/react';
import { AlertTriangle, MessageSquareIcon, PhoneIcon, StarIcon, type NavIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';

export type QrSubmissionKind = 'feedback' | 'suggestion' | 'complaint';

/** The institution's public number as the server validated it: a tel: link and the way people write it. */
export interface PublicQrCall {
    href: string;
    display: string;
}

/** Same icon and colour as the matching choice card above, so the two read as one set of actions. */
const SUBMISSIONS: Record<QrSubmissionKind, { label: string; Icon: NavIcon; tone: string }> = {
    feedback: { label: 'serviceFeedback.dockFeedback', Icon: StarIcon, tone: 'text-amber-600 dark:text-amber-300' },
    suggestion: { label: 'serviceFeedback.dockSuggestion', Icon: MessageSquareIcon, tone: 'text-sky-700 dark:text-sky-300' },
    complaint: { label: 'serviceFeedback.dockComplaint', Icon: AlertTriangle, tone: 'text-rose-600 dark:text-rose-300' },
};

const itemClass = 'relative flex min-h-[60px] w-full flex-col items-center justify-center gap-1 rounded-xl px-1 py-2 text-center text-[13px] font-semibold leading-tight [overflow-wrap:anywhere] transition-colors hover:bg-[color:var(--app-surface-muted)] active:bg-[color:var(--app-surface-muted)] focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-[color:var(--color-primary)]';

/**
 * The bottom navigation of the scanned QR screens: the submissions the policy
 * allows, and Call when the institution has a public number. It sits under the
 * thumb on every screen of the flow — the choice screen, each form and the
 * receipt — so a visitor who opened the wrong form switches with one tap
 * instead of going back first. `FeedbackAppLayout` pins it to the bottom edge
 * and keeps the screen above it clear of it; this component is only the bar.
 *
 * Every URL and the number come from the server: the links carry only the
 * scanned token, and Call is the institution's validated public line — the
 * scanned institution's, or the one the scanned employee serves — never an
 * employee's own number. Without a number there is no Call slot; the
 * remaining actions share the width.
 */
export default function PublicQrActionDock({ hrefs, offered, call, target, active }: {
    hrefs: Record<QrSubmissionKind, string>;
    offered: QrSubmissionKind[];
    call: PublicQrCall | null;
    target: 'employee' | 'organization';
    /** The screen the visitor is on, marked in the bar; absent on the choice and receipt screens. */
    active?: QrSubmissionKind;
}) {
    const { t } = useLocale();

    if (offered.length === 0 && call === null) return null;

    const callName = call
        ? t(target === 'employee' ? 'serviceFeedback.callEmployeeInstitutionAria' : 'serviceFeedback.callInstitutionAria').replace('{number}', call.display)
        : '';

    return (
        <nav
            aria-label={t('serviceFeedback.dockLabel')}
            className="border-t border-[color:var(--app-border)] bg-[color:var(--app-surface)] px-1 pb-[env(safe-area-inset-bottom)] pt-1 shadow-[0_-1px_8px_rgba(15,23,42,0.06)] sm:border-x sm:border-[color:var(--app-border)]"
        >
            <ul className="flex">
                {offered.map((kind) => {
                    const { label, Icon, tone } = SUBMISSIONS[kind];
                    const current = kind === active;

                    return (
                        <li key={kind} className="flex min-w-0 flex-1">
                            <Link
                                href={hrefs[kind]}
                                aria-current={current ? 'page' : undefined}
                                className={`${itemClass} ${current ? 'text-[color:var(--color-primary)]' : 'text-[color:var(--app-foreground)]'}`}
                            >
                                {current && <span className="absolute inset-x-3 top-0 h-[3px] rounded-full bg-[color:var(--color-primary)]" aria-hidden="true" />}
                                <Icon className={`h-6 w-6 shrink-0 ${tone}`} aria-hidden="true" />
                                <span>{t(label)}</span>
                            </Link>
                        </li>
                    );
                })}
                {call && (
                    <li className="flex min-w-0 flex-1">
                        {/* A plain link: the dialler opens only when the visitor taps it. */}
                        <a href={call.href} aria-label={callName} className={`${itemClass} text-[color:var(--app-foreground)]`}>
                            <PhoneIcon className="h-6 w-6 shrink-0 text-[color:var(--color-primary)]" aria-hidden="true" />
                            <span>{t('serviceFeedback.dockCall')}</span>
                        </a>
                    </li>
                )}
            </ul>
        </nav>
    );
}
