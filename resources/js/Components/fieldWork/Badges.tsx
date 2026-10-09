import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';
import type { LocationValidation, MonitoringFlag } from './types';

export function FieldWorkStatusBadge({ status }: { status: string }) {
    const { t } = useLocale();

    return <StatusBadge status={status} label={t(`fieldWork.status.${status}`)} />;
}

/** Derived monitoring flags (never stored): overdue, check-in missing, supervisor not resolved. */
export function FlagBadge({ flag }: { flag: MonitoringFlag | 'supervisor_not_resolved' | 'gps_issues' }) {
    const { t } = useLocale();

    return <StatusBadge status={flag === 'overdue' ? 'overdue' : flag} label={t(`fieldWork.flags.${flag}`)} />;
}

/** GPS verification outcome. This, not the coordinates, is what ordinary viewers see. */
export function LocationBadge({ status }: { status: LocationValidation }) {
    const { t } = useLocale();

    return <StatusBadge status={status} label={t(`fieldWork.locationStatus.${status}`)} />;
}
