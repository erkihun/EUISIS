import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { Workspace } from '@/Components/grievances/Workspace';
import { CaseNumber, GPill, HandlerName, Table, TablePanel, tdCls, thCls } from '@/Components/grievances/ui';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { AppealsIndexProps } from '@/types/grievances';

export default function Index({ appeals }: AppealsIndexProps) {
    const { t } = useLocale();
    return <Workspace title={t('grievanceWork.appeals')}><TablePanel page={appeals} empty={t('grievanceCases.empty')}><Table head={<>{['case_number', 'status', 'from', 'to', 'date'].map(k => <th className={thCls} key={k}>{t(`grievances.common.${k}`)}</th>)}</>}>{appeals.data.map(a => <tr key={a.id}><td className={tdCls}><Link href={route('grievances.cases.show', a.grievance_id)}><CaseNumber value={a.reference_number} /></Link></td><td className={tdCls}><GPill group="appeal_status" value={a.status} /></td><td className={tdCls}><HandlerName handler={a.from} /></td><td className={tdCls}><HandlerName handler={a.to} /></td><td className={tdCls}><DateDisplay value={a.filed_at} /></td></tr>)}</Table></TablePanel></Workspace>;
}
