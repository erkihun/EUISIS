import { type ReactNode, useId } from 'react';

interface Props {
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
    action?: ReactNode;
}

export default function ChartCard({ title, description, children, footer, action }: Props) {
    const titleId = useId();

    return (
        <section
            aria-labelledby={titleId}
            className="min-w-0 overflow-hidden rounded-xl border border-[color:var(--app-border)] bg-[color:var(--app-surface)]"
        >
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-[color:var(--app-border)] px-4 py-3 sm:px-5">
                <h2 id={titleId} className="text-sm font-semibold text-[color:var(--app-foreground)]">
                    {title}
                </h2>
                {action}
                {description && (
                    <p className="mt-1 text-sm text-[color:var(--app-muted-foreground)]">
                        {description}
                    </p>
                )}
            </div>
            {/* No min-height: a card with nothing to plot should shrink to its
                message rather than reserve a chart's worth of blank space. */}
            <div className="p-4 sm:p-5">{children}</div>
            {footer && <div className="border-t border-[color:var(--app-border)] px-5 py-4">{footer}</div>}
        </section>
    );
}
