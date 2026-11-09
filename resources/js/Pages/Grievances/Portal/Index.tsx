import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { ActionForm, CaseTable, Workspace } from '@/Components/grievances/Workspace';
import { Stat, primaryBtn, useEnumLabel } from '@/Components/grievances/ui';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { PortalIndexProps } from '@/types/grievances';

export default function Index({ grievances, statuses, filters, summary, can }: PortalIndexProps) {
    const { t } = useLocale(); const label = useEnumLabel();
    return <Workspace portal title={t('grievancePortal.title')} actions={can.create && <Link className={primaryBtn} href={route('employee.grievances.create')}>{t('grievancePortal.create')}</Link>}>
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">{(['open', 'drafts', 'awaiting_response', 'decision_issued'] as const).map(k => <Stat key={k} label={t(`grievancePortal.${k}`)} value={summary[k]} />)}</div>
        {summary.next_appeal_deadline && <p>{t('grievances.common.appeal_deadline')}: <DateDisplay value={summary.next_appeal_deadline} /></p>}
        <ActionForm method="get" url={route('employee.grievances.index')} initial={filters} submitLabel={t('grievances.common.filter')} fields={[{ name: 'status', label: t('grievances.common.status'), type: 'select', options: statuses.map(s => ({ value: s, label: label('status', s) })) }]} />
        <CaseTable page={grievances} portal />
    </Workspace>;
}
