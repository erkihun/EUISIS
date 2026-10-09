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
            className="min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900"
        >
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b border-gray-100 px-4 py-3 sm:px-5 dark:border-slate-800">
                <h2 id={titleId} className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {title}
                </h2>
                {action}
                {description && (
                    <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                        {description}
                    </p>
                )}
            </div>
            {/* No min-height: a card with nothing to plot should shrink to its
                message rather than reserve a chart's worth of blank space. */}
            <div className="p-4 sm:p-5">{children}</div>
            {footer && <div className="border-t border-gray-100 px-5 py-4 dark:border-slate-800">{footer}</div>}
        </section>
    );
}
