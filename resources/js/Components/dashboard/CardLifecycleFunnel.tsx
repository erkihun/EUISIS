import EmptyDashboardState from './EmptyDashboardState';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';

interface Item {
    key: string;
    value: number;
}

interface Props {
    data: Item[];
    t: (key: string) => string;
}

/**
 * Card requests and cards by stage, in stage order. The stages are one ordered
 * series, so every bar takes the one primary hue and its length carries the
 * count (a hue per stage would read as unrelated categories); each count is
 * printed once, beside its stage name.
 */
export default function CardLifecycleFunnel({ data, t }: Props) {
    const { number } = useDisplayFormat();
    if (data.length === 0 || data.every((item) => item.value === 0)) {
        return <EmptyDashboardState compact title={t('dashboard.noData')} />;
    }

    const max = Math.max(...data.map((item) => item.value), 1);

    return (
        <ol className="space-y-3">
            {data.map((item) => (
                <li key={item.key}>
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="min-w-0 wrap-break-word text-(--app-muted-foreground)">{t(`dashboard.cardLifecycle.${item.key}`)}</span>
                        <span className="shrink-0 font-semibold tabular-nums text-(--app-foreground)">{number(item.value)}</span>
                    </div>
                    <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-(--app-surface-muted)" aria-hidden="true">
                        <div
                            className="h-full origin-left rounded-full bg-primary transition-transform duration-700 ease-out starting:scale-x-0 motion-reduce:transition-none rtl:origin-right"
                            style={{ width: item.value > 0 ? `${Math.max(2, (item.value / max) * 100)}%` : '0%' }}
                        />
                    </div>
                </li>
            ))}
        </ol>
    );
}
