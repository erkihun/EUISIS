import { ChartLineIcon, FileChartIcon, Inbox, StarIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { StatusBadge, cx, type Tone } from '@euisis/ui';
import { Link } from '@inertiajs/react';
import { useCallback, type JSX, type ReactNode } from 'react';

export type NamePair = { en: string | null; am: string | null } | null;

/** One record as ServiceFeedbackController::summarise() shapes it. */
export type FeedbackSummary = {
    id: string;
    rating: number;
    comment: string | null;
    status: string;
    client_name?: string | null;
    client_contact?: string | null;
    created_at: string | null;
    employee: { id: string; name: string | null; employee_number: string | null } | null;
    organization: NamePair;
    organization_unit?: NamePair;
    position?: NamePair;
    service_no?: string | null;
    service_type: NamePair;
};

export const MAX_RATING = 5;

/*
 * One scale for every rating on these screens, whether a single star value or
 * an average: below 2.5 reads as dissatisfied, below 3.5 as neutral. The
 * number is always printed beside the colour, so the tone is never the only
 * signal.
 */
export type RatingTone = 'low' | 'mid' | 'high';

export function ratingTone(value: number): RatingTone {
    if (value < 2.5) return 'low';
    return value < 3.5 ? 'mid' : 'high';
}

export const toneClasses: Record<RatingTone, { pill: string; text: string; bar: string; soft: string }> = {
    low: {
        pill: 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300',
        text: 'text-red-700 dark:text-red-400',
        bar: 'bg-red-600 dark:bg-red-500',
        soft: 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300',
    },
    mid: {
        pill: 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300',
        text: 'text-amber-700 dark:text-amber-400',
        bar: 'bg-amber-500',
        soft: 'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300',
    },
    high: {
        pill: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
        text: 'text-emerald-700 dark:text-emerald-400',
        bar: 'bg-emerald-600 dark:bg-emerald-500',
        soft: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
    },
};

/** Compact star value, coloured by how satisfied the client was. */
export function RatingPill({ rating, className }: { rating: number; className?: string }): JSX.Element {
    return (
        <span
            className={cx('inline-flex h-6 shrink-0 items-center gap-1 rounded-[var(--radius-control)] px-2 text-[13px] font-bold tabular-nums', toneClasses[ratingTone(rating)].pill, className)}
            title={`${rating} / ${MAX_RATING}`}
        >
            <StarIcon aria-hidden="true" className="h-3 w-3 fill-current stroke-none" />
            {rating}
            <span className="sr-only"> / {MAX_RATING}</span>
        </span>
    );
}

/** Thin horizontal meter for an average on the five-point scale. */
export function RatingBar({ value, className }: { value: number; className?: string }): JSX.Element {
    return (
        <span aria-hidden="true" className={cx('block h-1.5 overflow-hidden rounded-full bg-[color:var(--app-surface-muted)]', className)}>
            <span
                className={cx('block h-full rounded-full', toneClasses[ratingTone(value)].bar)}
                style={{ width: `${Math.min(100, (value / MAX_RATING) * 100)}%` }}
            />
        </span>
    );
}

const statusTones: Record<string, Tone> = {
    pending: 'warning',
    reviewed: 'info',
    resolved: 'success',
    hidden: 'neutral',
};

export function statusLabelKey(status: string): string {
    return `serviceFeedback.status${status.charAt(0).toUpperCase()}${status.slice(1)}`;
}

/*
 * Feedback has its own badge rather than the global StatusBadge: here
 * "pending" means an administrator still owes a review, so it takes the
 * warning tone, and the label is translated.
 */
export function FeedbackStatusBadge({ status, className }: { status: string; className?: string }): JSX.Element {
    const { t } = useLocale();

    return (
        <StatusBadge tone={statusTones[status] ?? 'neutral'} className={cx('whitespace-nowrap', className)}>
            {t(statusLabelKey(status))}
        </StatusBadge>
    );
}

/** Bilingual name pair → the label for the current locale. */
export function useNameLabel(): (pair: NamePair | undefined, fallback?: string) => string {
    const { locale } = useLocale();

    return useCallback(
        (pair, fallback = '—') => (locale === 'am' ? (pair?.am ?? pair?.en) : pair?.en) ?? fallback,
        [locale],
    );
}

/** CSV export link carrying the screen's current filters. */
export function exportHref(filters: Record<string, string | undefined>): string {
    const params = new URLSearchParams(
        Object.entries(filters).filter(([, value]) => value !== undefined && value !== '') as [string, string][],
    ).toString();

    return params ? `${route('service-feedback.admin.export')}?${params}` : route('service-feedback.admin.export');
}

export function percent(part: number, whole: number): number {
    return whole > 0 ? Math.round((part / whole) * 1000) / 10 : 0;
}

type Section = 'overview' | 'inbox' | 'reports';

/**
 * Title block and the Overview / Inbox / Reports tabs shared by the three
 * module screens, so moving between them never needs the sidebar or a Back
 * link.
 */
export function FeedbackPageHeader({
    current,
    title,
    description,
    actions,
    pendingCount,
}: {
    current: Section;
    title: string;
    description?: string;
    actions?: ReactNode;
    /** Shown on the Inbox tab when a review is owed. */
    pendingCount?: number;
}): JSX.Element {
    const { t } = useLocale();

    const tabs: { id: Section; label: string; href: string; icon: (p: { className?: string }) => JSX.Element; badge?: number }[] = [
        { id: 'overview', label: t('serviceFeedback.overview'), href: route('service-feedback.admin.dashboard'), icon: ChartLineIcon },
        { id: 'inbox', label: t('serviceFeedback.inbox'), href: route('service-feedback.admin.index'), icon: Inbox, badge: pendingCount },
        { id: 'reports', label: t('serviceFeedback.reports'), href: route('service-feedback.admin.reports'), icon: FileChartIcon },
    ];

    return (
        <header className="space-y-4">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                <div className="min-w-0">
                    <p className="text-xs font-semibold uppercase tracking-[0.06em] text-[color:var(--app-muted-foreground)]">
                        {t('serviceFeedback.clientFeedback')}
                    </p>
                    <h1 className="mt-1 text-2xl font-bold leading-tight text-[color:var(--app-foreground)]">{title}</h1>
                    {description && <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">{description}</p>}
                </div>
                {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
            </div>

            <nav aria-label={t('serviceFeedback.sectionsNav')} className="-mx-4 overflow-x-auto px-4 [scrollbar-width:none] sm:mx-0 sm:px-0 [&::-webkit-scrollbar]:hidden">
                <ul className="flex min-w-max gap-1 border-b border-[color:var(--app-border)]">
                    {tabs.map((tab) => {
                        const active = tab.id === current;
                        return (
                            <li key={tab.id}>
                                <Link
                                    href={tab.href}
                                    aria-current={active ? 'page' : undefined}
                                    className={cx(
                                        '-mb-px inline-flex items-center gap-2 border-b-2 px-3.5 py-2.5 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]',
                                        active
                                            ? 'border-[color:var(--color-primary)] font-semibold text-[color:var(--color-primary)]'
                                            : 'border-transparent font-medium text-[color:var(--app-muted-foreground)] hover:border-[color:var(--app-border-strong)] hover:text-[color:var(--app-foreground)]',
                                    )}
                                >
                                    <tab.icon className="h-4 w-4" />
                                    {tab.label}
                                    {tab.badge !== undefined && tab.badge > 0 && (
                                        <span className="inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full bg-amber-100 px-1.5 text-[11px] font-bold tabular-nums text-amber-800 dark:bg-amber-900/50 dark:text-amber-200">
                                            {tab.badge > 999 ? '999+' : tab.badge}
                                        </span>
                                    )}
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            </nav>
        </header>
    );
}
