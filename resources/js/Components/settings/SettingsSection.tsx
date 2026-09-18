import type { PropsWithChildren, ReactNode } from 'react';

type Props = PropsWithChildren<{
    title: string;
    description?: string;
    actions?: ReactNode;
}>;

export default function SettingsSection({ title, description, actions, children }: Props) {
    return (
        <section className="space-y-4">
            <div className="flex flex-col gap-3 rounded-panel border border-gray-200 bg-white px-5 py-4 sm:flex-row sm:items-start sm:justify-between dark:border-slate-800 dark:bg-slate-900">
                <div>
                    <h2 className="text-lg font-semibold text-gray-900 dark:text-slate-100">{title}</h2>
                    {description && (
                        <p className="mt-1 max-w-3xl text-sm text-gray-500 dark:text-slate-400">{description}</p>
                    )}
                </div>

                {actions && <div className="shrink-0">{actions}</div>}
            </div>

            {children}
        </section>
    );
}
