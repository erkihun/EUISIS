import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    label: string;
    /** The part the meter fills with. */
    value: number;
    total: number;
    valueLabel: string;
    restLabel: string;
    /** Shown instead of the meter when `total` is zero. */
    emptyLabel?: string;
}

/**
 * A single ratio: the percentage as the figure, a meter in one hue (the track
 * is a lighter step of the same colour), and both parts named with their
 * counts so nothing depends on colour. The fill grows in once on first paint
 * (Tailwind's `starting:` variant) and stays still for reduced motion.
 */
export default function RatioMeter({ label, value, total, valueLabel, restLabel, emptyLabel }: Props) {
    const { t } = useLocale();
    const { number } = useDisplayFormat();

    if (total <= 0) {
        return (
            <div>
                <p className="text-xs font-medium text-(--app-muted-foreground)">{label}</p>
                <p className="mt-2 text-sm text-(--app-muted-foreground)">{emptyLabel ?? t('dashboard.noData')}</p>
            </div>
        );
    }

    const share = Math.min(Math.max(value / total, 0), 1);
    const percent = `${Math.round(share * 100)}%`;
    const of = t('dashboard.overviewCharts.ofTotal').replaceAll(':count', number(value)).replaceAll(':total', number(total));

    return (
        <div>
            <p className="text-xs font-medium text-(--app-muted-foreground)">{label}</p>
            <p className="mt-1 flex flex-wrap items-baseline gap-x-2">
                <span className="text-3xl font-semibold text-(--app-foreground)">{percent}</span>
                <span className="text-sm text-(--app-muted-foreground)">{of}</span>
            </p>
            <div
                role="meter"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={total}
                aria-valuenow={value}
                aria-valuetext={`${percent} (${of})`}
                className="mt-3 h-2 overflow-hidden rounded-full bg-primary/15"
            >
                <div
                    className="h-full origin-left rounded-full bg-primary transition-transform duration-700 ease-out starting:scale-x-0 motion-reduce:transition-none rtl:origin-right"
                    style={{ width: `${share * 100}%` }}
                />
            </div>
            <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
                <div className="flex min-w-0 items-center gap-2">
                    <span aria-hidden="true" className="h-2.5 w-2.5 shrink-0 rounded-xs bg-primary" />
                    <dt className="min-w-0 wrap-break-word text-(--app-muted-foreground)">{valueLabel}</dt>
                    <dd className="ms-auto font-semibold tabular-nums text-(--app-foreground)">{number(value)}</dd>
                </div>
                <div className="flex min-w-0 items-center gap-2">
                    <span aria-hidden="true" className="h-2.5 w-2.5 shrink-0 rounded-xs bg-primary/15 inset-ring inset-ring-primary/30" />
                    <dt className="min-w-0 wrap-break-word text-(--app-muted-foreground)">{restLabel}</dt>
                    <dd className="ms-auto font-semibold tabular-nums text-(--app-foreground)">{number(total - value)}</dd>
                </div>
            </dl>
        </div>
    );
}
