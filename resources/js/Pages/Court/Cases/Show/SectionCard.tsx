import type { ReactNode } from 'react';
import { Card, cx } from '@/Components/ui';

interface Props {
    id: string;
    title: ReactNode;
    icon?: ReactNode;
    /** The count badge at the right of the header ("3 entries"). */
    count?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
    /** Without padding, for a section that paints its own (the message thread). */
    flush?: boolean;
}

/** One section of the case page: a card with the Blade page's icon + title header and count badge. */
export default function SectionCard({ id, title, icon, count, actions, children, className, flush = false }: Props) {
    return (
        <Card id={id} className={cx(flush ? 'overflow-hidden p-0' : 'space-y-5', 'scroll-mt-24', className)}>
            <div className={cx('flex flex-wrap items-center justify-between gap-3 border-b border-(--app-border) pb-3', flush && 'bg-(--app-surface-muted) px-5 py-4')}>
                <h3 className="flex items-center gap-2.5 text-base font-semibold text-(--app-foreground)">
                    {icon && <span className="grid h-7 w-7 place-items-center rounded-control bg-primary/10 text-primary" aria-hidden="true">{icon}</span>}
                    {title}
                </h3>
                <div className="flex flex-wrap items-center gap-2">
                    {actions}
                    {count !== undefined && (
                        <span className="rounded-full border border-(--app-border) bg-(--app-surface-muted) px-2.5 py-0.5 text-[11px] font-semibold text-(--app-muted-foreground)">{count}</span>
                    )}
                </div>
            </div>
            {children}
        </Card>
    );
}

/** The dashed empty box the Blade sections used when a list had nothing in it. */
export function EmptyBox({ children }: { children: ReactNode }) {
    return <div className="rounded-control border border-dashed border-(--app-border) bg-(--app-surface-muted) p-6 text-center text-sm text-(--app-muted-foreground)">{children}</div>;
}

/** A small "label over value" tile from the summary section. */
export function MetaTile({ label, children, className }: { label: ReactNode; children: ReactNode; className?: string }) {
    return (
        <div className={cx('rounded-control border border-(--app-border) bg-(--app-surface-muted) p-4', className)}>
            <div className="mb-1 text-[11px] font-semibold uppercase tracking-wider text-(--app-muted-foreground)">{label}</div>
            <div className="text-sm font-semibold text-(--app-foreground)">{children}</div>
        </div>
    );
}
