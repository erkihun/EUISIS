import { type HTMLAttributes, type ReactNode } from 'react';
import { cx } from './primitives';
import { useUiMessages } from './context';

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';
/** The tone's surface alone, for a table row or cell that carries a status (a matching field, an invalid value). */
const surfaces: Record<Tone, string> = {
    neutral: 'bg-gray-50 dark:bg-slate-800/60',
    info: 'bg-primary-50 dark:bg-primary-950',
    success: 'bg-emerald-50 dark:bg-emerald-950/40',
    warning: 'bg-amber-50 dark:bg-amber-950/40',
    danger: 'bg-red-50 dark:bg-red-950/40',
};
const tones: Record<Tone, string> = {
    neutral: `${surfaces.neutral} text-gray-700 ring-gray-200 dark:text-slate-300 dark:ring-slate-700`,
    info: `${surfaces.info} text-primary-800 ring-primary-200 dark:text-primary-200 dark:ring-primary-800`,
    success: `${surfaces.success} text-emerald-800 ring-emerald-200 dark:text-emerald-300 dark:ring-emerald-900`,
    warning: `${surfaces.warning} text-amber-800 ring-amber-200 dark:text-amber-300 dark:ring-amber-900`,
    danger: `${surfaces.danger} text-red-800 ring-red-200 dark:text-red-300 dark:ring-red-900`,
};

export function toneSurfaceClassName(tone: Tone = 'neutral', className?: string): string {
    return cx(surfaces[tone], className);
}

/** The badge style, for markup that must stay a plain element. */
export function badgeClassName(tone: Tone = 'neutral', className?: string): string {
    return cx('inline-flex items-center rounded-control px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone], className);
}

export function StatusBadge({ tone = 'neutral', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
    return <span className={badgeClassName(tone, className)}>{children}</span>;
}

export function Alert({ tone = 'info', title, children, className, ...props }: Omit<HTMLAttributes<HTMLDivElement>, 'title'> & { tone?: Tone; title?: ReactNode }) {
    return <div role={tone === 'danger' ? 'alert' : 'status'} className={cx('rounded-card p-4 text-sm ring-1 ring-inset', tones[tone], className)} {...props}>{title && <p className="font-semibold">{title}</p>}{children && <div className={title ? 'mt-1' : ''}>{children}</div>}</div>;
}

export function EmptyState({ title, description, action, icon, className }: { title?: ReactNode; description?: ReactNode; action?: ReactNode; icon?: ReactNode; className?: string }) {
    const messages = useUiMessages();
    return <div className={cx('px-6 py-12 text-center', className)}>{icon && <div className="mx-auto mb-3 flex justify-center text-(--app-muted-foreground)" aria-hidden="true">{icon}</div>}<p className="text-sm font-medium">{title ?? messages.noResults}</p>{description && <p className="mx-auto mt-1 max-w-md text-sm text-(--app-muted-foreground)">{description}</p>}{action && <div className="mt-4 flex justify-center">{action}</div>}</div>;
}

export function ErrorState({ title, description, action, className }: { title: ReactNode; description?: ReactNode; action?: ReactNode; className?: string }) {
    return <Alert tone="danger" title={title} className={cx('text-center', className)}>{description}{action && <div className="mt-3 flex justify-center">{action}</div>}</Alert>;
}

export function Tooltip({ content, children }: { content: ReactNode; children: ReactNode }) {
    return <span className="group relative inline-flex"><span>{children}</span><span role="tooltip" className="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 hidden -translate-x-1/2 whitespace-nowrap rounded-sm bg-slate-900 px-2 py-1 text-xs text-white shadow-lg group-hover:block group-focus-within:block">{content}</span></span>;
}
