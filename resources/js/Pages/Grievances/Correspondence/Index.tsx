import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { ActionForm, Workspace } from '@/Components/grievances/Workspace';
import { CaseNumber, GPill, Pager, Table, TablePanel, primaryBtn, tdCls, thCls, useEnumLabel } from '@/Components/grievances/ui';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { CorrespondenceIndexProps } from '@/types/grievances';

const CHANNELS = ['in_app', 'email', 'sms_notice', 'printed', 'official_registry', 'other'];

/** Correspondence register: letters the user may see, with registry actions (seal, issue, dispatch). */
export default function Index({ letters, filters, options, seals, can }: CorrespondenceIndexProps) {
    const { t } = useLocale();
    const label = useEnumLabel();
    const w = (k: string) => t(`grievanceWork.${k}`);

    return <Workspace title={w('correspondence')}>
        <p className="text-sm text-gray-600 dark:text-slate-400">{w('correspondence_help')}</p>
        <ActionForm method="get" url={route('grievances.correspondence.index')} initial={{ ...filters }} submitLabel={t('grievances.common.filter')} fields={[
            { name: 'search', label: w('search_reference') },
            { name: 'status', label: t('grievances.common.status'), type: 'select', options: options.statuses.map(v => ({ value: v, label: label('letter_status', v) })) },
            { name: 'letter_type', label: t('grievances.common.type'), type: 'select', options: options.types.map(v => ({ value: v, label: label('letter_type', v) })) },
        ]} />
        <TablePanel page={letters} empty={w('no_letters')}>
            <Table head={<>{['reference', 'case', 'type', 'subject', 'status', 'signed', 'issued', 'actions'].map(k => <th key={k} className={thCls}>{w(`col_${k}`)}</th>)}</>}>
                {letters.data.map(l => <tr key={l.id}>
                    <td className={tdCls}><span className="font-mono text-xs">{l.reference_number ?? '—'}</span></td>
                    <td className={tdCls}><Link href={route('grievances.cases.show', l.grievance_id) + '?tab=letters'}><CaseNumber value={l.case_number} /></Link></td>
                    <td className={tdCls}>{label('letter_type', l.letter_type)}<span className="block text-xs">{label('letter_language', l.language)}</span></td>
                    <td className={tdCls}>{l.subject}</td>
                    <td className={tdCls}><GPill group="letter_status" value={l.status} /></td>
                    <td className={tdCls}>{l.signatory ?? '—'}{l.sealed && <span className="block text-xs">{w('sealed')}</span>}</td>
                    <td className={tdCls}><DateDisplay value={l.issued_at} /></td>
                    <td className={tdCls}><div className="space-y-2">
                        {l.has_pdf && <a className={primaryBtn} href={route('grievances.cases.letters.download', [l.grievance_id, l.id])}>{t('grievances.common.download')}</a>}
                        {l.status === 'signed' && can.seal && !l.sealed && <ActionForm url={route('grievances.cases.letters.transition', [l.grievance_id, l.id])} initial={{ action: 'seal' }} submitLabel={w('apply_seal')} fields={[{ name: 'seal_id', label: w('seal'), type: 'select', required: true, options: seals.filter(s => s.organization_id === l.organization_id).map(s => ({ value: s.id, label: s.name })) }]} />}
                        {l.status === 'signed' && can.issue && <ActionForm url={route('grievances.cases.letters.transition', [l.grievance_id, l.id])} initial={{ action: 'issue' }} submitLabel={w('issue')} />}
                        {l.status === 'issued' && can.issue && <ActionForm url={route('grievances.cases.letters.dispatch', [l.grievance_id, l.id])} submitLabel={w('dispatch')} fields={[{ name: 'channel', label: w('channel'), type: 'select', required: true, options: CHANNELS.map(v => ({ value: v, label: label('dispatch_channel', v) })) }, { name: 'notes', label: t('grievances.common.notes'), maxLength: 255 }]} />}
                    </div></td>
                </tr>)}
            </Table>
        </TablePanel>
        <Pager page={letters} />
    </Workspace>;
}
