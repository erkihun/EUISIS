import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';

/** Status of a stored daily log (draft … approved). */
export function LogStatusBadge({ status }: { status: string }) {
    const { t } = useLocale();

    return <StatusBadge status={status} label={t(`dailyActivities.statuses.${status}`)} />;
}

/** Calculated status of a calendar day (missing, leave, holiday …). */
export function DayStatusBadge({ status }: { status: string }) {
    const { t } = useLocale();

    return <StatusBadge status={status} label={t(`dailyActivities.dayStatuses.${status}`)} />;
}

export function LateMark() {
    const { t } = useLocale();

    return (
        <span className="rounded-control bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-800 ring-1 ring-inset ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900">
            {t('dailyActivities.summary.late')}
        </span>
    );
}
