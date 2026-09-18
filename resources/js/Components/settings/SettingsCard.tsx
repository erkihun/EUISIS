import type { PropsWithChildren, ReactNode } from 'react';

type Props = PropsWithChildren<{
    title: string;
    description?: string;
    actions?: ReactNode;
    footer?: ReactNode;
}>;

export default function SettingsCard({ title, description, actions, footer, children }: Props) {
    return (
        <div className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-start sm:justify-between dark:border-slate-800">
                <div>
                    <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h3>
                    {description && (
                        <p className="mt-1 max-w-3xl text-xs text-gray-500 dark:text-slate-400">{description}</p>
                    )}
                </div>

                {actions && <div className="shrink-0">{actions}</div>}
            </div>

            <div className="divide-y divide-gray-100 dark:divide-slate-800">{children}</div>

            {footer && (
                <div className="border-t border-gray-100 bg-gray-50 px-5 py-3 dark:border-slate-800 dark:bg-slate-950/40">
                    {footer}
                </div>
            )}
        </div>
    );
}
