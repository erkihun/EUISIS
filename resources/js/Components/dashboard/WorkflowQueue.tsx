import EmptyDashboardState from './EmptyDashboardState';

interface Item {
    key: string;
    labelKey: string;
    count: number;
    href: string;
    tone: 'primary' | 'warning' | 'neutral';
}

interface Props {
    items: Item[];
    t: (key: string) => string;
}

export default function WorkflowQueue({ items, t }: Props) {
    if (items.length === 0) {
        return <EmptyDashboardState title={t('dashboard.noPendingTasks')} compact />;
    }

    return (
        <div className="space-y-3">
            {items.map((item) => (
                <div key={item.key} className="flex items-center justify-between rounded-panel border border-gray-200 px-4 py-3 dark:border-slate-800">
                    <div>
                        <p className="text-sm font-medium text-gray-900 dark:text-slate-100">{t(item.labelKey)}</p>
                        <p className="mt-1 text-2xl font-semibold text-gray-900 dark:text-slate-100">{item.count}</p>
                    </div>
                    <a
                        href={item.href}
                        className="inline-flex h-9 items-center justify-center rounded-lg bg-[color:var(--color-primary)] px-4 text-sm font-medium text-white transition hover:bg-[color:var(--color-primary-hover)]"
                    >
                        {t('dashboard.viewDetails')}
                    </a>
                </div>
            ))}
        </div>
    );
}
