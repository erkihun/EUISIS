import type { ReactNode } from 'react';
import { Card, cx } from '@/Components/ui';

/**
 * A profile section: tinted icon, title and one-line description in a header
 * band, the content below. Every card on the page uses it, so the three tabs
 * read as one surface.
 */
export default function SectionCard({
    id,
    icon,
    title,
    description,
    actions,
    flush = false,
    children,
}: {
    id: string;
    icon: ReactNode;
    title: string;
    description?: string;
    actions?: ReactNode;
    /** The content brings its own padding (a list of rows, a table). */
    flush?: boolean;
    children: ReactNode;
}) {
    return (
        <Card className="p-0" aria-labelledby={id}>
            <div className="flex flex-wrap items-start gap-3 border-b border-(--app-border) px-(--card-padding) py-4">
                <span aria-hidden="true" className="flex h-9 w-9 shrink-0 items-center justify-center rounded-control bg-primary/10 text-primary">
                    {icon}
                </span>
                <div className="min-w-0 flex-1 basis-56">
                    <h2 id={id} className="text-section-title text-(--app-foreground)">{title}</h2>
                    {description && <p className="mt-0.5 text-sm text-(--app-muted-foreground)">{description}</p>}
                </div>
                {actions && <div className="flex shrink-0 flex-wrap gap-2">{actions}</div>}
            </div>
            <div className={cx(!flush && 'p-(--card-padding)')}>{children}</div>
        </Card>
    );
}
