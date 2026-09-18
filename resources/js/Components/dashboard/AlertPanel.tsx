import EmptyDashboardState from './EmptyDashboardState';

interface AlertItem {
    key: string;
    titleKey: string;
    descriptionKey: string;
    severity: 'warning' | 'critical' | 'info';
    count: number;
    href: string;
}

interface Props {
    alerts: AlertItem[];
    t: (key: string) => string;
}

const severityStyles = {
    info: 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900/30 dark:bg-blue-950/30 dark:text-blue-200',
    warning: 'border-orange-200 bg-orange-50 text-orange-800 dark:border-orange-900/30 dark:bg-orange-950/30 dark:text-orange-200',
    critical: 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/30 dark:bg-red-950/30 dark:text-red-200',
} as const;

export default function AlertPanel({ alerts, t }: Props) {
    if (alerts.length === 0) {
        return <EmptyDashboardState title={t('dashboard.noAlerts')} compact />;
    }

    return (
        <div className="space-y-3">
            {alerts.map((alert) => (
                <a
                    key={alert.key}
                    href={alert.href}
                    className={`block rounded-panel border px-4 py-3 transition hover: ${severityStyles[alert.severity]}`}
                >
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-sm font-semibold">{t(alert.titleKey)}</p>
                            <p className="mt-1 text-sm opacity-90">{t(alert.descriptionKey)}</p>
                        </div>
                        <span className="rounded-full bg-white/70 px-2.5 py-0.5 text-sm font-semibold dark:bg-slate-900/60">
                            {alert.count}
                        </span>
                    </div>
                </a>
            ))}
        </div>
    );
}
