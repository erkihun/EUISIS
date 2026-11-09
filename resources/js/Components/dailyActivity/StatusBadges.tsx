import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';

import { badgeClassName } from '@/Components/ui';
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
        <span className={badgeClassName('warning')}>
            {t('dailyActivities.summary.late')}
        </span>
    );
}
