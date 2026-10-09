import EmptyDashboardState from './EmptyDashboardState';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

interface ActivityItem {
    id: string;
    event: string;
    actor: string;
    subject: string | null;
    timestamp: string | null;
    severity: 'info' | 'warning' | 'critical';
}

interface Props {
    items: ActivityItem[];
    t: (key: string) => string;
}

const severityDot: Record<ActivityItem['severity'], string> = {
    info:     'bg-blue-400 dark:bg-blue-500',
    warning:  'bg-amber-400 dark:bg-amber-500',
    critical: 'bg-red-500 dark:bg-red-400',
};

/** Convert a raw event type value (dots/underscores) to the i18n key under auditLogs.events. */
function eventKey(eventType: string): string {
    return eventType.replace(/\./g, '_');
}

export default function RecentActivityFeed({ items, t }: Props) {
    if (items.length === 0) {
        return <EmptyDashboardState title={t('dashboard.noRecentActivity')} compact />;
    }

    function eventLabel(eventType: string): string {
        // Primary: auditLogs.events.* (covers all 130+ event types in EN + AM)
        const key = `auditLogs.events.${eventKey(eventType)}` as Parameters<typeof t>[0];
        const translated = t(key);
        if (translated !== key) return translated;

        // Fallback 1: dashboard.auditEvents.* (legacy subset, ~20 events)
        const legacyKey = `dashboard.auditEvents.${eventType}` as Parameters<typeof t>[0];
        const legacy = t(legacyKey);
        if (legacy !== legacyKey) return legacy;

        // Fallback 2: humanise the raw value
        return eventType.replace(/[._-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    }

    return (
        <div className="divide-y divide-gray-100 dark:divide-slate-800">
            {items.map((item) => (
                <div
                    key={item.id}
                    className="grid grid-cols-[8px_minmax(0,1fr)] items-start gap-x-3 gap-y-1 py-3 first:pt-0 last:pb-0 sm:grid-cols-[8px_minmax(0,1fr)_auto]"
                >
                    {/* Severity indicator */}
                    <span
                        className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${severityDot[item.severity]}`}
                        aria-hidden="true"
                    />

                    <div className="min-w-0 flex-1">
                        {/* Event label */}
                        <p className="text-sm font-medium text-gray-900 dark:text-slate-100">
                            {eventLabel(item.event)}
                        </p>

                        {/* Actor + subject */}
                        <p className="mt-0.5 break-words text-xs text-gray-500 dark:text-slate-400">
                            {item.actor !== 'system' ? item.actor : t('auditLogs.system')}
                            {item.subject && (
                                <span className="ml-1 text-gray-400 dark:text-slate-500">
                                    · {item.subject}
                                </span>
                            )}
                        </p>
                    </div>

                    {/* Localized timestamp */}
                    <div className="col-start-2 text-left sm:col-start-3 sm:text-right">
                        <LocalizedDateDisplay
                            value={item.timestamp}
                            withTime
                            className="text-xs text-gray-400 dark:text-slate-500"
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}
