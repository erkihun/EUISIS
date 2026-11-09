import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { ActionForm, TabLinks, Workspace } from '@/Components/grievances/Workspace';
import { CaseNumber, GPill, HandlerName, Section, Pager, secondaryBtn } from '@/Components/grievances/ui';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { ApprovalsIndexProps } from '@/types/grievances';

export default function Index({ decisions, tab, counts }: ApprovalsIndexProps) {
    const { t } = useLocale();
    return <Workspace title={t('grievanceWork.approvals')}><TabLinks items={['pending', 'history'].map(v => ({ label: `${t(`grievanceWork.${v}`)}${v === 'pending' ? ` (${counts.pending})` : ''}`, href: route('grievances.approvals.index', { tab: v }), active: tab === v }))} />{decisions.data.map(d => <Section key={d.id} title={`${d.reference_number} · ${d.version_no}`} actions={<Link className={secondaryBtn} href={route('grievances.cases.show', d.grievance_id)}>{t('grievanceWork.review_case')}</Link>}><div className="mb-4 flex flex-wrap items-center gap-3"><CaseNumber value={d.reference_number} /><GPill group="decision_status" value={d.status} /><HandlerName handler={d.handler} /><DateDisplay value={d.approval_due_at} /></div>{d.can_act && <ActionForm url={route('grievances.cases.decisions.transition', [d.grievance_id, d.id])} title={t('grievanceWork.record_approval')} fields={[{ name: 'action', label: t('grievances.common.actions'), type: 'select', required: true, options: ['approve', 'return_for_correction', 'reject'].map(value => ({ value, label: t(`grievanceWork.${value}`) })) }, { name: 'comment', label: t('grievances.common.comment'), type: 'textarea', help: t('grievanceWork.comment_required') }]} />}</Section>)}{!decisions.data.length && <p>{t('grievanceCases.empty')}</p>}<Pager page={decisions} /></Workspace>;
}
