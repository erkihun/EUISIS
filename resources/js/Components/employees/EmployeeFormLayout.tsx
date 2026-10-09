import type { PropsWithChildren, ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

/**
 * Shared building blocks for the Employee Create and Edit forms.
 *
 * Both pages render the same five sections in the same order, so the styling
 * lives here rather than being copied into each page. Nothing in this file
 * knows about form state or routes — the pages own that.
 */

/** Shared control styling. Exported so the pages style their own inputs identically. */
export const inputCls =
    'w-full rounded-control border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder-gray-400 transition focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder-slate-500 dark:disabled:bg-slate-900 dark:disabled:text-slate-500';

export const labelCls = 'mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400';

export const helpCls = 'mt-1 text-xs text-gray-400 dark:text-slate-500';

/**
 * A titled card holding one group of fields.
 *
 * Cards stack in a single column on mobile and lay their fields out in a
 * two-column grid from `md` up, which is the layout the admin area uses
 * everywhere else.
 */
export function FormCard({
    icon,
    title,
    description,
    aside,
    grid = true,
    wide = false,
    children,
}: PropsWithChildren<{
    icon: ReactNode;
    title: string;
    description?: string;
    /** Trailing header content, e.g. a "Position Selected" badge. */
    aside?: ReactNode;
    /** false renders children stacked instead of in the two-column grid. */
    grid?: boolean;
    /**
     * Adds a third column from `xl` up, for a page that runs the full width of
     * the content area. Two columns across 1,600px leaves every control absurdly
     * wide, so a full-width page opts in rather than inheriting the narrow
     * page's proportions.
     */
    wide?: boolean;
}>) {
    return (
        <section className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 bg-gray-50/70 px-5 py-4 dark:border-slate-800 dark:bg-slate-950/40">
                <div className="flex min-w-0 items-start gap-3">
                    <span
                        className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-blue-50 text-[color:var(--color-primary)] dark:bg-blue-500/10 dark:text-[color:var(--color-primary)]"
                        aria-hidden="true"
                    >
                        {icon}
                    </span>
                    <div className="min-w-0">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h2>
                        {description && (
                            <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{description}</p>
                        )}
                    </div>
                </div>
                {aside && <div className="shrink-0">{aside}</div>}
            </header>

            <div className={`p-5 ${grid ? `grid gap-x-5 gap-y-4 md:grid-cols-2${wide ? ' xl:grid-cols-3' : ''}` : 'space-y-4'}`}>{children}</div>
        </section>
    );
}

/**
 * One labelled control.
 *
 * `required` renders the asterisk AND an accessible "Required" hint, so the
 * marker is not colour-and-glyph only. The error sits directly beneath the
 * control it belongs to.
 */
export function Field({
    label,
    error,
    help,
    required = false,
    htmlFor,
    className,
    children,
}: PropsWithChildren<{
    label: string;
    error?: string;
    help?: string;
    required?: boolean;
    htmlFor?: string;
    className?: string;
}>) {
    const { t } = useLocale();

    return (
        <div className={`min-w-0 ${className ?? ''}`}>
            <label className={labelCls} htmlFor={htmlFor}>
                {label}
                {required && (
                    <>
                        <span aria-hidden="true" className="ml-0.5 text-red-500">
                            *
                        </span>
                        <span className="sr-only"> ({t('employees.requiredField')})</span>
                    </>
                )}
            </label>
            {children}
            {help && <p className={helpCls}>{help}</p>}
            {error && (
                <p role="alert" className="mt-1 text-xs text-red-600 dark:text-red-400">
                    {error}
                </p>
            )}
        </div>
    );
}

/** A resolved, non-editable value shown in place of a control. */
export function ReadOnlyValue({
    label,
    value,
    code,
}: {
    label: string;
    value: string;
    code?: string | null;
}) {
    return (
        <div className="min-w-0">
            <dt className={labelCls}>{label}</dt>
            <dd className="truncate text-sm font-semibold text-gray-900 dark:text-slate-100">{value}</dd>
            {code && <dd className="mt-0.5 truncate font-mono text-[11px] text-gray-400 dark:text-slate-500">{code}</dd>}
        </div>
    );
}

/** The green "Position Selected" pill shown in the Employment card header. */
export function PositionSelectedBadge() {
    const { t } = useLocale();

    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/30">
            <svg className="h-3 w-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path
                    fillRule="evenodd"
                    d="M16.704 4.153a.75.75 0 0 1 .143 1.052l-8 10.5a.75.75 0 0 1-1.127.075l-4.5-4.5a.75.75 0 0 1 1.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 0 1 1.05-.143Z"
                    clipRule="evenodd"
                />
            </svg>
            {t('employees.positionSelected')}
        </span>
    );
}

/**
 * The action bar pinned to the bottom of the viewport.
 *
 * `onSaveAndView` is optional: a page that has only one save target passes
 * nothing and the second button is not rendered.
 */
export function FormActions({
    processing,
    cancelHref,
    saveLabel,
    onSaveAndView,
}: {
    processing: boolean;
    cancelHref: string;
    saveLabel: string;
    onSaveAndView?: () => void;
}) {
    const { t } = useLocale();

    return (
        <div className="sticky bottom-0 z-10 -mx-4 mt-6 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 dark:border-slate-800 dark:bg-slate-900/95">
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end sm:gap-3">
                <Link
                    href={cancelHref}
                    className="rounded-lg px-4 py-2 text-center text-sm font-medium text-gray-700 transition hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                >
                    {t('common.cancel')}
                </Link>

                {onSaveAndView && (
                    <button
                        type="button"
                        onClick={onSaveAndView}
                        disabled={processing}
                        className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800 dark:focus:ring-offset-slate-900"
                    >
                        {t('employees.saveAndView')}
                    </button>
                )}

                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex items-center justify-center gap-2 rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white transition hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)] focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 dark:focus:ring-offset-slate-900"
                >
                    {processing && (
                        <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                            <path
                                className="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"
                            />
                        </svg>
                    )}
                    {processing ? t('common.saving') : saveLabel}
                </button>
            </div>
        </div>
    );
}

/** Section icons. Inline so no icon dependency is added for five glyphs. */
const iconProps = {
    className: 'h-4 w-4',
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.8,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
};

export function BasicIcon() {
    return (
        <svg {...iconProps}>
            <path d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7Z" />
        </svg>
    );
}

export function ContactIcon() {
    return (
        <svg {...iconProps}>
            <path d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102A1.125 1.125 0 0 0 5.872 2.25H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" />
        </svg>
    );
}

export function EmploymentIcon() {
    return (
        <svg {...iconProps}>
            <path d="M20.25 14.15v4.073a2.25 2.25 0 0 1-1.632 2.163l-1.32.377a12.4 12.4 0 0 1-6.796 0l-1.32-.377a2.25 2.25 0 0 1-1.632-2.163V14.15M3.75 8.25h16.5M9 11.25h6M8.25 6V4.5A1.5 1.5 0 0 1 9.75 3h4.5a1.5 1.5 0 0 1 1.5 1.5V6" />
            <rect x="2.25" y="6" width="19.5" height="8.25" rx="2" />
        </svg>
    );
}

export function EmergencyIcon() {
    return (
        <svg {...iconProps}>
            <path d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12Z" />
        </svg>
    );
}

export function SystemIcon() {
    return (
        <svg {...iconProps}>
            <path d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z" />
        </svg>
    );
}
