import { useLocale } from '@/hooks/useLocale';
import { ActionForm, type InputField } from './Workspace';
import { GPill, Section, useEnumLabel } from './ui';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { CaseShowProps, DecisionDetail } from '@/types/grievances';

export function DecisionForm({ grievanceId, decision, types }: { grievanceId: string; decision?: DecisionDetail; types: string[] }) {
    const { t } = useLocale(); const label = useEnumLabel();
    const textFields = ['findings', 'facts_considered', 'legal_basis', 'analysis', 'decision_text', 'recommendations'] as const;
    const fields: InputField[] = [{ name: 'decision_type', label: t('grievanceWork.decision_type'), type: 'select', options: types.map(v => ({ value: v, label: label('decision_type', v) })) }, ...textFields.map(name => ({ name, label: t(`grievanceWork.${name}`), type: 'textarea' as const, required: name === 'decision_text' })), ...['corrective_action_required', 'disciplinary_referral_recommended'].map(name => ({ name, label: t(`grievanceWork.${name}`), type: 'checkbox' as const }))];
    return <ActionForm url={decision ? route('grievances.cases.decisions.update', [grievanceId, decision.id]) : route('grievances.cases.decisions.store', grievanceId)} method={decision ? 'patch' : 'post'} title={t('grievanceWork.save_decision')} fields={fields} initial={decision ? { ...Object.fromEntries(textFields.map(k => [k, decision[k] ?? ''])), decision_type: decision.decision_type, corrective_action_required: decision.corrective_action_required, disciplinary_referral_recommended: decision.disciplinary_referral_recommended } : {}} />;
}

export function DecisionPanel({ props }: { props: CaseShowProps }) {
    const { t } = useLocale(); const label = useEnumLabel(); const { grievance: g, tabs, can, options } = props;
    return <div className="space-y-4">{can.draft_decision && !tabs.decisions?.some(d => d.stage_id === g.current_stage?.id && !['superseded', 'returned_for_correction', 'rejected'].includes(d.status)) && <Section title={t('grievanceWork.new_decision')}><DecisionForm grievanceId={g.id} types={options.decision_types ?? []} /></Section>}
        {tabs.decisions?.map(d => {
            const current = d.stage_id === g.current_stage?.id;
            const actions: string[] = [];
            if (current && d.status === 'draft' && can.draft_decision) actions.push('submit_for_review');
            if (current && d.status === 'under_internal_review' && can.review_decision) actions.push('endorse', 'return_internal');
            if (current && ['draft', 'under_internal_review'].includes(d.status) && can.submit_decision) actions.push('submit_for_approval');
            if (current && ['draft', 'under_internal_review', 'approved'].includes(d.status) && can.finalize_decision) actions.push('finalize');
            return <Section key={`${d.id}-${d.status}`} title={`${t('grievanceWork.decision')} · ${d.version_no}`}><div className="mb-4 flex gap-3"><GPill group="decision_status" value={d.status} /><GPill group="decision_type" value={d.decision_type} /><DateDisplay value={d.created_at} /></div>
                {can.draft_decision && current && d.status === 'draft' ? <DecisionForm grievanceId={g.id} decision={d} types={options.decision_types ?? []} /> : <dl className="space-y-3">{(['findings', 'facts_considered', 'legal_basis', 'analysis', 'decision_text', 'recommendations'] as const).filter(k => d[k]).map(k => <div key={k}><dt className="text-sm font-semibold">{t(`grievanceWork.${k}`)}</dt><dd className="whitespace-pre-wrap">{d[k]}</dd></div>)}</dl>}
                {actions.length > 0 && <div className="mt-4"><ActionForm url={route('grievances.cases.decisions.transition', [g.id, d.id])} title={t('grievanceWork.transition')} fields={[{ name: 'action', label: t('grievances.common.actions'), type: 'select', required: true, options: actions.map(v => ({ value: v, label: t(`grievanceWork.${v}`) })) }, { name: 'comment', label: t('grievances.common.comment'), type: 'textarea' }]} /></div>}
                {can.draft_decision && current && ['returned_for_correction', 'rejected'].includes(d.status) && <ActionForm url={route('grievances.cases.decisions.revise', [g.id, d.id])} title={t('grievanceWork.revise')} />}
                {can.vote && current && ['draft', 'under_internal_review'].includes(d.status) && <div className="mt-4"><ActionForm url={route('grievances.cases.decisions.vote', [g.id, d.id])} title={t('grievanceWork.vote')} fields={[{ name: 'vote', label: t('grievanceWork.vote'), type: 'select', required: true, options: (options.vote_types ?? []).map(v => ({ value: v, label: label('vote_type', v) })) }, { name: 'opinion', label: t('grievanceWork.opinion'), type: 'textarea' }]} /></div>}
                <ul className="mt-4 space-y-2">{d.approvals.map(a => <li key={a.id}><GPill group="approval_action" value={a.action} /> {a.actor} · <DateDisplay value={a.acted_at} withTime /><p className="whitespace-pre-wrap">{a.comment}</p></li>)}</ul>
                <ul className="mt-4 space-y-2">{d.votes.map((v, i) => <li key={i}><GPill group="vote_type" value={v.vote} /> {v.employee?.name} <p>{v.opinion}</p></li>)}</ul>
            </Section>;
        })}</div>;
}
