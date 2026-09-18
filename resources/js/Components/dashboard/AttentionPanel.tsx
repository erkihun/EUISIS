import { Link } from '@inertiajs/react';
import { ChevronRight } from '@/Components/Icons';
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
    count: number;
    href: string;
    rank: number;
};

/* Dot colour is reinforcement only — the label and count carry the meaning. */
const rankDot = ['bg-red-600', 'bg-amber-500', 'bg-[color:var(--color-primary)]'];

interface Props {
    alerts: AlertItem[];
    queues: QueueItem[];
    t: (key: string) => string;
    title: string;
}

/**
 * The one thing on the page that answers "what do I do today?".
 *
 * Alerts and workflow queues were previously two separate panels in a narrow
 * right-hand column, below the fold on a laptop — so the two blocks that
 * actually demand action were the least likely to be seen. They are the same
 * kind of thing (a count of items waiting on a person) and are merged here
 * into one list at the top of the page, ordered by urgency.
 *
 * Renders nothing at all when there is nothing waiting. An "All clear" card
 * would occupy the most valuable space on the screen to say that nothing
 * needs doing; its absence says the same thing and costs no room.
 */
export default function AttentionPanel({ alerts, queues, t, title }: Props) {
    const { number } = useDisplayFormat();

    const rows: Row[] = [
        ...alerts
            .filter((alert) => alert.count > 0)
            .map((alert) => ({
                key: `alert-${alert.key}`,
                label: t(alert.titleKey),
                count: alert.count,
                href: alert.href,
                rank: alert.severity === 'critical' ? 0 : alert.severity === 'warning' ? 1 : 2,
            })),
        ...queues
            .filter((queue) => queue.count > 0)
            .map((queue) => ({
                key: `queue-${queue.key}`,
                label: t(queue.labelKey),
                count: queue.count,
                href: queue.href,
                rank: queue.tone === 'warning' ? 1 : 2,
            })),
    ].sort((a, b) => a.rank - b.rank || b.count - a.count);

    if (rows.length === 0) return null;

    return (
        <section
            aria-label={title}
            className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900"
        >
            <h2 className="border-b border-gray-100 px-4 py-2.5 text-sm font-semibold text-gray-900 dark:border-slate-800 dark:text-slate-100">
                {title}
            </h2>

            <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                {rows.map((row) => (
                    <li key={row.key}>
                        <Link
                            href={row.href}
                            className="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[color:var(--color-primary)] dark:hover:bg-slate-800/60"
                        >
                            <span
                                aria-hidden="true"
                                className={`h-1.5 w-1.5 shrink-0 rounded-full ${rankDot[row.rank]}`}
                            />
                            <span className="min-w-0 flex-1 truncate text-sm text-gray-700 dark:text-slate-300">
                                {row.label}
                            </span>
                            <span className="shrink-0 text-sm font-semibold tabular-nums text-gray-900 dark:text-slate-100">
                                {number(row.count)}
                            </span>
                            <ChevronRight
                                aria-hidden="true"
                                className="h-4 w-4 shrink-0 text-gray-400 dark:text-slate-600"
                            />
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
