import type { ReactNode } from 'react';

export const panelCls = 'portal-panel rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900';

export const inputCls =
    'mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

export const labelCls = 'block text-xs font-medium text-gray-600 dark:text-slate-400';

export const primaryBtn =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] disabled:cursor-not-allowed disabled:opacity-50';

export const secondaryBtn =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800';

export const linkBtn = 'text-sm font-medium text-[color:var(--color-primary)] hover:underline';

/** A titled block. The badge says, at a glance, whether its fields can be edited here. */
export function Block({ title, badge, badgeTone = 'neutral', description, children, actions }: {
    title: string;
    badge?: string;
    badgeTone?: 'neutral' | 'editable';
    description?: string;
    children: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <section className={panelCls}>
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-5 py-5 sm:px-6 dark:border-slate-800">
                <div className="min-w-0">
                    <h2 className="flex flex-wrap items-center gap-2 text-base font-semibold text-gray-900 dark:text-slate-100">
                        {title}
                        {badge && (
                            <span className={[
                                'rounded-control px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset',
                                badgeTone === 'editable'
                                    ? 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900'
                                    : 'bg-gray-50 text-gray-600 ring-gray-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700',
                            ].join(' ')}>{badge}</span>
                        )}
                    </h2>
                    {description && <p className="mt-2 max-w-2xl text-sm leading-relaxed text-gray-500 dark:text-slate-400">{description}</p>}
                </div>
                {actions}
            </header>
            <div className="px-5 py-5 sm:px-6">{children}</div>
        </section>
    );
}

/** Marks a field that is printed on the employee's current physical card. */
export function PrintedChip({ label }: { label: string }) {
    return (
        <span className="rounded-control bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
            {label}
        </span>
    );
}

export function FieldErrors({ errors }: { errors: Record<string, string | undefined> }) {
    const messages = Object.values(errors).filter(Boolean);
    if (!messages.length) return null;

    return (
        <div role="alert" className="mt-2 space-y-0.5 text-sm text-red-700 dark:text-red-400">
            {messages.map((message, index) => <p key={index}>{message}</p>)}
        </div>
    );
}
