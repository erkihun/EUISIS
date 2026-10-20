import { Link } from '@inertiajs/react';
import { badgeClassName, buttonClassName } from '@/Components/ui';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';

export type AlertItem = {
    key: string;
    titleKey: string;
    descriptionKey: string;
    severity: 'warning' | 'critical' | 'info';
    count: number;
    href: string;
};

export type QueueItem = {
    key: string;
    labelKey: string;
    count: number;
    href: string;
    tone: 'primary' | 'warning' | 'neutral';
};

type Row = {
    key: string;
    label: string;
    description: string | null;
    count: number;
    href: string;
    rank: number;
};

/* Dot colour is reinforcement only — the label and count carry the meaning. */
const rankDot = ['bg-red-600', 'bg-amber-500', 'bg-primary'];

interface Props {
    alerts: AlertItem[];
    queues: QueueItem[];
    t: (key: string) => string;
    title: string;
}

/**
 * The one thing on the page that answers "what do I do today?".
 *
 * Alerts and workflow queues are the same kind of thing — a count of items
 * waiting on a person — so they are one list, ordered by urgency, each row
 * naming what it is, how many, and where to act. Renders nothing when
 * nothing is waiting: an "all clear" card would spend the most valuable space
 * on the screen to say that nothing needs doing.
 */
export default function AttentionPanel({ alerts, queues, t, title }: Props) {
    const { number } = useDisplayFormat();

    const rows: Row[] = [
        ...alerts
            .filter((alert) => alert.count > 0)
            .map((alert) => ({
                key: `alert-${alert.key}`,
                label: t(alert.titleKey),
                description: alert.descriptionKey && t(alert.descriptionKey) !== alert.descriptionKey ? t(alert.descriptionKey) : null,
                count: alert.count,
                href: alert.href,
                rank: alert.severity === 'critical' ? 0 : alert.severity === 'warning' ? 1 : 2,
            })),
        ...queues
            .filter((queue) => queue.count > 0)
            .map((queue) => ({
                key: `queue-${queue.key}`,
                label: t(queue.labelKey),
                description: null,
                count: queue.count,
                href: queue.href,
                rank: queue.tone === 'warning' ? 1 : 2,
            })),
    ].sort((a, b) => a.rank - b.rank || b.count - a.count);

    if (rows.length === 0) return null;

    const worst = Math.min(...rows.map((row) => row.rank));
    const countTone = worst === 0 ? 'danger' : worst === 1 ? 'warning' : 'info';

    return (
        <section aria-labelledby="dashboard-attention-title" className="overflow-hidden rounded-panel border border-(--app-border) bg-(--app-surface)">
            <div className="flex items-center justify-between gap-3 border-b border-(--app-border) px-4 py-3">
                <h2 id="dashboard-attention-title" className="text-base font-semibold text-(--app-foreground)">{title}</h2>
                <span className={badgeClassName(countTone)}>
                    {number(rows.length)} {t(rows.length === 1 ? 'dashboard.attention.item' : 'dashboard.attention.items')}
                </span>
            </div>
            <ul className="divide-y divide-(--app-border)">
                {rows.map((row) => (
                    <li key={row.key} className="flex items-center justify-between gap-3 px-4 py-3">
                        <div className="flex min-w-0 items-center gap-3">
                            <span className={`h-2 w-2 shrink-0 rounded-full ${rankDot[row.rank]}`} aria-hidden="true" />
                            <div className="min-w-0">
                                <p className="m-0 text-sm font-medium text-(--app-foreground) wrap-break-word">{row.label}</p>
                                {row.description && <p className="m-0 text-xs text-(--app-muted-foreground) wrap-break-word">{row.description}</p>}
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-3">
                            <span className="text-xl font-semibold tabular-nums text-(--app-foreground)">{number(row.count)}</span>
                            <Link href={row.href} className={buttonClassName({ variant: 'outline', size: 'sm' })}>
                                {t('dashboard.attention.open')}
                                <span className="sr-only"> · {row.label}</span>
                            </Link>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
