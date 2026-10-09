import { type HTMLAttributes, type ReactNode } from 'react';
import { cx } from './primitives';
import { useUiMessages } from './context';

export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';
const tones: Record<Tone, string> = {
    neutral: 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-slate-800/60 dark:text-slate-300 dark:ring-slate-700',
    info: 'bg-[color:var(--color-primary-50)] text-[color:var(--color-primary-800)] ring-[color:var(--color-primary-200)] dark:bg-[color:var(--color-primary-950)] dark:text-[color:var(--color-primary-200)] dark:ring-[color:var(--color-primary-800)]',
    success: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900',
    warning: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900',
    danger: 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-950/40 dark:text-red-300 dark:ring-red-900',
};

export function StatusBadge({ tone = 'neutral', children, className }: { tone?: Tone; children: ReactNode; className?: string }) {
    return <span className={cx('inline-flex items-center rounded-[var(--radius-control)] px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tones[tone], className)}>{children}</span>;
}

export function Alert({ tone = 'info', title, children, className, ...props }: Omit<HTMLAttributes<HTMLDivElement>, 'title'> & { tone?: Tone; title?: ReactNode }) {
    return <div role={tone === 'danger' ? 'alert' : 'status'} className={cx('rounded-[var(--radius-card)] p-4 text-sm ring-1 ring-inset', tones[tone], className)} {...props}>{title && <p className="font-semibold">{title}</p>}{children && <div className={title ? 'mt-1' : ''}>{children}</div>}</div>;
}

export function EmptyState({ title, description, action, icon, className }: { title?: ReactNode; description?: ReactNode; action?: ReactNode; icon?: ReactNode; className?: string }) {
    const messages = useUiMessages();
    return <div className={cx('px-6 py-12 text-center', className)}>{icon && <div className="mx-auto mb-3 flex justify-center text-[color:var(--app-muted-foreground)]" aria-hidden="true">{icon}</div>}<p className="text-sm font-medium">{title ?? messages.noResults}</p>{description && <p className="mx-auto mt-1 max-w-md text-sm text-[color:var(--app-muted-foreground)]">{description}</p>}{action && <div className="mt-4 flex justify-center">{action}</div>}</div>;
}

export function ErrorState({ title, description, action, className }: { title: ReactNode; description?: ReactNode; action?: ReactNode; className?: string }) {
    return <Alert tone="danger" title={title} className={cx('text-center', className)}>{description}{action && <div className="mt-3 flex justify-center">{action}</div>}</Alert>;
}

export function Tooltip({ content, children }: { content: ReactNode; children: ReactNode }) {
    return <span className="group relative inline-flex"><span>{children}</span><span role="tooltip" className="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 hidden -translate-x-1/2 whitespace-nowrap rounded bg-slate-900 px-2 py-1 text-xs text-white shadow-lg group-hover:block group-focus-within:block">{content}</span></span>;
}
