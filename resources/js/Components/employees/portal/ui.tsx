import type { ReactNode } from 'react';

import { badgeClassName } from '@/Components/ui';
export const panelCls = 'portal-panel rounded-panel border border-(--app-border) bg-(--app-surface)';

export const inputCls =
    'mt-1 w-full rounded-lg border border-(--app-border-strong) bg-(--app-surface) px-3 py-2 text-sm text-(--app-foreground) focus:border-primary focus:outline-hidden focus:ring-1 focus:ring-primary';

export const labelCls = 'block text-xs font-medium text-(--app-muted-foreground)';

export const primaryBtn =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-50';

export const secondaryBtn =
    'inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-(--app-border-strong) bg-(--app-surface) px-4 py-2 text-sm font-medium text-(--app-foreground) hover:bg-(--app-surface-muted) disabled:cursor-not-allowed disabled:opacity-50';

export const linkBtn = 'text-sm font-medium text-primary hover:underline';

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
            <header className="flex flex-wrap items-start justify-between gap-3 border-b border-(--app-border) px-5 py-5 sm:px-6">
                <div className="min-w-0">
                    <h2 className="flex flex-wrap items-center gap-2 text-base font-semibold text-(--app-foreground)">
                        {title}
                        {badge && (
                            <span className={[
                                'rounded-control px-1.5 py-0.5 text-[11px] font-medium ring-1 ring-inset',
                                badgeTone === 'editable'
                                    ? 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900'
                                    : 'bg-(--app-surface-muted) text-(--app-muted-foreground) ring-(--app-border-strong)',
                            ].join(' ')}>{badge}</span>
                        )}
                    </h2>
                    {description && <p className="mt-2 max-w-2xl text-sm leading-relaxed text-(--app-muted-foreground)">{description}</p>}
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
        <span className={badgeClassName('warning')}>
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
