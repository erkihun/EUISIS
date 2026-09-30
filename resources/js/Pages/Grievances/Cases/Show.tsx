import { useState, type ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { ActionForm, CaseBanner, Timeline, Workspace, type InputField } from '@/Components/grievances/Workspace';
import { DecisionPanel } from '@/Components/grievances/DecisionPanel';
import { Details, Empty, GPill, HandlerName, Section, SlaBadge, Table, employeeName, fileSize, nameOf, primaryBtn, secondaryBtn, tdCls, thCls, useEnumLabel } from '@/Components/grievances/ui';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { CaseShowProps, ReasonCode } from '@/types/grievances';

type Tab = { id: string; label: string; content: ReactNode };

/**
 * Staff case page. Everything shown is already filtered by the server
 * (tabs only exist when the viewer may see them); every action is shown only
 * when its server-computed `can` flag is true and is re-checked server-side.
 */
export default function Show(props: CaseShowProps) {
    const { grievance: g, tabs, options, viewer, can } = props;
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const c = (k: string) => t(`grievanceCases.${k}`);
    const r = (name: string, extra: (string | number)[] = []) => route(`grievances.cases.${name}`, [g.id, ...extra]);
    const opts = (values: string[] | undefined, group: string) => (values ?? []).map(v => ({ value: v, label: label(group, v) }));
    const reasons = (list: ReasonCode[] | undefined) => (list ?? []).map(x => ({ value: x.code, label: nameOf(x, locale) }));
    const stage = g.current_stage;

    const [tab, setTab] = useState<string>(() => new URLSearchParams(window.location.search).get('tab') ?? 'overview');
    const select = (id: string) => { setTab(id); const u = new URL(window.location.href); u.searchParams.set('tab', id); window.history.replaceState(window.history.state, '', u); };

    // ── Actions (right-hand workflow panel) ──────────────────────────────────
    const actions: { key: string; title: string; node: ReactNode }[] = [];
    if (can.intake) {
        actions.push({ key: 'intake', title: c('intake'), node: <ActionForm url={r('intake')} submitLabel={c('intake_submit')} fields={[
            { name: 'action', label: c('intake_decision'), type: 'select', required: true, options: [
                { value: 'accept', label: c('intake_accept') }, { value: 'return', label: c('intake_return') },
                ...(options.allow_intake_rejection ? [{ value: 'reject', label: c('intake_reject') }] : []),
            ] },
            { name: 'reason_code', label: t('grievances.common.reason_code'), type: 'select', options: [...reasons(options.reason_codes?.intake_return), ...reasons(options.reason_codes?.intake_rejection)], help: c('intake_reason_help') },
            { name: 'notes', label: t('grievances.common.notes'), type: 'textarea' },
        ]} /> });
    }
    if (can.receive) actions.push({ key: 'receive', title: c('receive'), node: <ActionForm url={r('receive')} submitLabel={c('receive')}><p className="text-sm">{c('receive_help')}</p></ActionForm> });
    if (can.start_review) actions.push({ key: 'review', title: c('start_review'), node: <ActionForm url={r('start-review')} submitLabel={c('start_review')}><p className="text-sm">{c('start_review_help')}</p></ActionForm> });
    if (can.decide_withdrawal) actions.push({ key: 'withdrawal', title: c('withdrawal'), node: <ActionForm url={r('withdrawal.decide')} fields={[
        { name: 'approve', label: c('approve_withdrawal'), type: 'checkbox' }, { name: 'notes', label: t('grievances.common.notes'), type: 'textarea' },
    ]}><p className="text-sm">{c('withdrawal_help')}</p></ActionForm> });
    const moveOptions = (['manual_escalation', 'reassigned', 'referred', 'returned'] as const).flatMap(m => (options.routes?.[m] ?? []).map(o => ({ value: `${m}|${o.route_id}`, label: `${label('movement_type', m)} → ${nameOf(o.handler, locale)}` })));
    if ((can.escalate || can.reassign || can.refer) && moveOptions.length) {
        actions.push({ key: 'move', title: c('move'), node: <MoveForm url={r('move')} options={moveOptions} reasons={reasons(options.reason_codes?.reassignment)} /> });
    }
    if (can.assign_officers) actions.push({ key: 'officer', title: c('assign_officer'), node: <ActionForm url={r('officers.store')} fields={[
        { name: 'user_id', label: c('officer'), type: 'select', required: true, options: (options.unit_officers ?? []).map(o => ({ value: String(o.id), label: o.name })) },
        { name: 'role', label: t('grievances.common.type'), type: 'select', required: true, options: opts(['lead', 'officer'], 'officer_role') },
    ]} /> });
    if (can.pause) actions.push({ key: 'pause', title: c('request_pause'), node: <ActionForm url={r('pauses.store')} fields={[
        { name: 'reason', label: t('grievances.common.reason'), type: 'select', required: true, options: opts(options.pause_reasons, 'pause_reason') },
        { name: 'notes', label: t('grievances.common.notes'), type: 'textarea' },
    ]}><p className="text-sm">{c('pause_help')}</p></ActionForm> });
    if (can.declare_recusal) actions.push({ key: 'recusal', title: c('declare_recusal'), node: <ActionForm url={r('recusals.store')} fields={[{ name: 'reason', label: t('grievances.common.reason'), type: 'textarea', required: true }]}><p className="text-sm">{c('recusal_help')}</p></ActionForm> });
    if (can.classify) actions.push({ key: 'classify', title: c('classify'), node: <ActionForm url={r('classify')} initial={{ confidentiality_level: g.confidentiality_level ?? '', priority: g.priority ?? '', respondent_type: g.respondent?.type ?? '', respondent_description: g.respondent?.description ?? '', root_cause_category: g.root_cause_category ?? '', systemic_issue_flag: g.systemic_issue_flag, corrective_action_required: g.corrective_action_required }} fields={[
        { name: 'confidentiality_level', label: t('grievances.common.confidentiality'), type: 'select', options: opts(['normal_confidential', 'restricted', 'highly_restricted'], 'confidentiality') },
        { name: 'priority', label: t('grievances.common.priority'), type: 'select', options: opts(['low', 'normal', 'high', 'urgent'], 'priority') },
        { name: 'respondent_type', label: c('respondent_type'), type: 'select', options: opts(['employee', 'organization_unit', 'decision', 'other'], 'respondent_type') },
        { name: 'root_cause_category', label: c('root_cause'), maxLength: 60 },
        { name: 'respondent_description', label: c('respondent'), type: 'textarea' },
        { name: 'systemic_issue_flag', label: c('systemic_issue'), type: 'checkbox' },
        { name: 'corrective_action_required', label: c('corrective_required'), type: 'checkbox' },
    ]}><p className="text-sm">{c('classify_help')}</p></ActionForm> });
    if (can.close) actions.push({ key: 'close', title: c('close_case'), node: <ActionForm url={r('close')} fields={[
        { name: 'reason_code', label: t('grievances.common.reason_code'), type: 'select', required: true, options: reasons(options.reason_codes?.closure) },
        { name: 'notes', label: t('grievances.common.notes'), type: 'textarea' },
    ]} /> });
    if (can.reopen) actions.push({ key: 'reopen', title: c('reopen'), node: <ActionForm url={r('reopen')} fields={[
        { name: 'reason_code', label: t('grievances.common.reason_code'), type: 'select', required: true, options: reasons(options.reason_codes?.reopen) },
        { name: 'reason', label: t('grievances.common.reason'), type: 'textarea', required: true },
    ]} /> });
    if (can.archive) actions.push({ key: 'retention', title: c('retention'), node: <div className="space-y-4">
        <ActionForm url={r('legal-hold')} initial={{ hold: !g.legal_hold }} submitLabel={g.legal_hold ? c('lift_hold') : c('set_hold')} fields={[{ name: 'reason', label: t('grievances.common.reason'), type: 'textarea', required: true }]} />
        <ActionForm url={r('archive')} submitLabel={c('archive')}><p className="text-sm">{c('archive_help')}</p></ActionForm>
    </div> });

    // ── Tabs ─────────────────────────────────────────────────────────────────
    const list: Tab[] = [];
    list.push({ id: 'overview', label: c('tab_overview'), content: <div className="space-y-4">
        {!viewer.details && <p className="text-sm">{t('grievances.common.no_access_details')}</p>}
        {viewer.oversight_only && <p className="rounded border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/30">{c('oversight_notice')}</p>}
        {viewer.pending_recusal && <p className="rounded border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/30">{c('recusal_notice')}</p>}
        {viewer.details && <Section title={t('grievances.common.description')}><p className="whitespace-pre-wrap">{g.description}</p></Section>}
        <Section title={t('grievances.common.details')}><Details items={[
            [t('grievances.common.category'), nameOf(g.category, locale)],
            [t('grievances.common.organization'), nameOf(g.organization, locale)],
            [t('grievances.common.organization_unit'), nameOf(g.organization_unit, locale)],
            [t('grievances.common.employee'), g.employee ? `${employeeName(g.employee, locale)} (${g.employee.employee_number ?? '—'})` : '—'],
            [t('grievances.common.incident_date'), <DateDisplay value={g.incident_date} />],
            [t('grievances.common.submitted'), <DateDisplay value={g.submitted_at} withTime />],
            [c('accepted_at'), <DateDisplay value={g.accepted_at} withTime />],
            [c('resolved_at'), <DateDisplay value={g.resolved_at} withTime />],
            [c('closed_at'), <DateDisplay value={g.closed_at} withTime />],
            [t('grievances.common.priority'), <GPill group="priority" value={g.priority} />],
            [c('respondent'), g.respondent ? [label('respondent_type', g.respondent.type), g.respondent.employee ? employeeName(g.respondent.employee, locale) : null, g.respondent.unit ? nameOf(g.respondent.unit, locale) : null, g.respondent.description].filter(Boolean).join(' · ') : '—'],
            [c('reason_codes'), [g.intake_reason_code, g.withdrawal_reason_code, g.closure_reason_code].filter(Boolean).join(' · ') || '—'],
            [c('legal_hold'), g.legal_hold ? `${t('grievances.common.yes')} — ${g.legal_hold_reason ?? ''}` : t('grievances.common.no')],
            [c('retention_until'), <DateDisplay value={g.retention_until} />],
            [c('systemic_issue'), g.systemic_issue_flag ? t('grievances.common.yes') : t('grievances.common.no')],
            [c('reopened'), String(g.reopened_count)],
        ]} /></Section>
        {g.intake_notes && <Section title={c('intake_notes')}><p className="whitespace-pre-wrap">{g.intake_notes}</p></Section>}
        {g.withdrawal_reason && <Section title={c('withdrawal')}><p className="whitespace-pre-wrap">{g.withdrawal_reason}</p></Section>}
        {!!tabs.amendments?.length && <Section title={c('amendments')} flush><Table head={<><th className={thCls}>{t('grievances.common.date')}</th><th className={thCls}>{c('changes')}</th><th className={thCls}>{t('grievances.common.reason')}</th></>}>
            {tabs.amendments.map(a => <tr key={a.id}><td className={tdCls}><DateDisplay value={a.at} withTime /><span className="block text-xs">{a.by}</span></td><td className={tdCls}>{Object.entries(a.changes).map(([f, v]) => <p key={f} className="text-xs"><strong>{f}</strong>: {String(v.from ?? '—')} → {String(v.to ?? '—')}</p>)}</td><td className={tdCls}>{a.reason ?? '—'}</td></tr>)}
        </Table></Section>}
    </div> });

    if (tabs.timeline) list.push({ id: 'timeline', label: c('tab_timeline'), content: <Section title={c('tab_timeline')}>{tabs.timeline.length ? <Timeline events={tabs.timeline} /> : <Empty>{c('empty')}</Empty>}</Section> });

    list.push({ id: 'stages', label: c('tab_stages'), content: <div className="space-y-4">
        {stage && <Section title={c('current_stage')}><Details items={[
            [t('grievances.common.handler'), <HandlerName handler={stage.handler} showType />],
            [t('grievances.common.stage'), `${stage.stage_no} · ${label('stage_status', stage.status)}`],
            [c('movement'), label('movement_type', stage.movement_type)],
            [t('grievances.common.received'), <DateDisplay value={stage.received_at ?? stage.created_at} withTime />],
            [t('grievances.common.due'), <DateDisplay value={stage.sla.due_at} withTime />],
            [t('grievances.common.original_due'), <DateDisplay value={stage.sla.original_due_at} withTime />],
            [t('grievances.common.sla'), <SlaBadge sla={stage.sla} />],
            [c('paused_days'), String(stage.sla.paused_days)],
        ]} />
            {stage.panel.length > 0 && <div className="mt-4"><h4 className="mb-2 text-sm font-semibold">{c('panel')}</h4><ul className="space-y-1 text-sm">{stage.panel.map(p => <li key={p.id}>{employeeName(p.employee, locale)} · {label('committee_role', p.role)}{p.source === 'replacement' ? ` · ${c('replacement')}` : ''}{p.recused_at ? ` · ${c('recused')}` : !p.is_active ? ` · ${c('left')}` : ''}</li>)}</ul></div>}
            {stage.officers.length > 0 && <div className="mt-4"><h4 className="mb-2 text-sm font-semibold">{c('officers')}</h4><ul className="space-y-2 text-sm">{stage.officers.map(o => <li key={o.id} className="flex flex-wrap items-center gap-2">{(locale === 'am' && o.name_am) || o.name} · {label('officer_role', o.role)}{o.released_at ? ` · ${c('released')}` : ''}{can.assign_officers && !o.released_at && <ActionForm url={r('officers.destroy', [o.id])} method="delete" submitLabel={c('release')} />}</li>)}</ul></div>}
        </Section>}
        {g.pauses.length > 0 && <Section title={c('pauses')} flush><Table head={<>{['reason', 'status', 'requested', 'period', 'due_change', 'actions'].map(k => <th key={k} className={thCls}>{c(`pause_${k}`)}</th>)}</>}>
            {g.pauses.map(p => <tr key={p.id}><td className={tdCls}>{label('pause_reason', p.reason)}<span className="block text-xs">{p.notes}</span></td><td className={tdCls}><GPill group="pause_status" value={p.status} /></td><td className={tdCls}>{p.requested_by}<span className="block text-xs"><DateDisplay value={p.requested_at} withTime /></span></td><td className={tdCls}><DateDisplay value={p.started_at} /> – <DateDisplay value={p.ended_at} />{p.paused_days !== null && <span className="block text-xs">{p.paused_days} {t('grievances.common.working_days_short')}</span>}</td><td className={tdCls}><DateDisplay value={p.due_at_before} /> → <DateDisplay value={p.due_at_after} /></td>
                <td className={tdCls}>{can.decide_pause && p.status === 'requested' && <div className="flex gap-2"><ActionForm url={r('pauses.decide', [p.id])} initial={{ action: 'approve' }} submitLabel={c('approve')} /><ActionForm url={r('pauses.decide', [p.id])} initial={{ action: 'reject' }} submitLabel={c('reject')} /></div>}{can.decide_pause && p.status === 'active' && <ActionForm url={r('pauses.decide', [p.id])} initial={{ action: 'resume' }} submitLabel={c('resume')} />}</td></tr>)}
        </Table></Section>}
        <Section title={c('stage_history')} flush><Table head={<>{['stage', 'handler', 'movement', 'status', 'received', 'due', 'completed'].map(k => <th key={k} className={thCls}>{c(`col_${k}`)}</th>)}</>}>
            {g.stages.map(s => <tr key={s.id}><td className={tdCls}>{s.stage_no}</td><td className={tdCls}><HandlerName handler={s.handler} showType /></td><td className={tdCls}>{label('movement_type', s.movement_type)}{s.movement_reason && <span className="block text-xs">{s.movement_reason}</span>}</td><td className={tdCls}><GPill group="stage_status" value={s.status} /></td><td className={tdCls}><DateDisplay value={s.received_at ?? s.created_at} /></td><td className={tdCls}><DateDisplay value={s.sla.due_at} /><div className="mt-1"><SlaBadge sla={s.sla} compact /></div></td><td className={tdCls}><DateDisplay value={s.completed_at} /></td></tr>)}
        </Table></Section>
        {!!tabs.recusals?.length && <Section title={c('recusals')} flush><Table head={<>{['member', 'reason', 'status', 'decision', 'actions'].map(k => <th key={k} className={thCls}>{c(`recusal_${k}`)}</th>)}</>}>
            {tabs.recusals.map(x => <tr key={x.id}><td className={tdCls}>{employeeName(x.employee, locale)}<span className="block text-xs"><DateDisplay value={x.declared_at} /></span></td><td className={tdCls}>{x.reason}</td><td className={tdCls}><GPill group="recusal_status" value={x.status} /></td><td className={tdCls}>{x.decided_by}{x.replacement && <span className="block text-xs">{c('replacement')}: {employeeName(x.replacement, locale)}</span>}{x.decision_notes && <span className="block text-xs">{x.decision_notes}</span>}</td>
                <td className={tdCls}>{can.decide_recusal && x.status === 'declared' && <RecusalDecision url={r('recusals.decide', [x.id])} organizationId={g.organization?.id} />}</td></tr>)}
        </Table></Section>}
    </div> });

    if (tabs.evidence) list.push({ id: 'evidence', label: c('tab_evidence'), content: <div className="space-y-4">
        <Section title={c('tab_evidence')} flush>{tabs.evidence.length ? <Table head={<>{['title', 'type', 'size', 'classification', 'status', 'submitted', 'actions'].map(k => <th key={k} className={thCls}>{c(`ev_${k}`)}</th>)}</>}>
            {tabs.evidence.map(e => <tr key={e.id}><td className={tdCls}><a className="text-[color:var(--color-primary)] underline" href={r('evidence.download', [e.id])}>{e.title}</a><span className="block text-xs">{e.original_name} · v{e.version_no}</span><span className="block font-mono text-[11px] text-gray-500" title={e.sha256}>SHA-256 {e.sha256.slice(0, 12)}…</span></td><td className={tdCls}>{label('evidence_type', e.evidence_type)}</td><td className={tdCls}>{fileSize(e.size_bytes)}</td><td className={tdCls}><GPill group="confidentiality" value={e.classification} /></td><td className={tdCls}><GPill group="evidence_status" value={e.status} /></td><td className={tdCls}>{e.submitted_by_complainant ? c('by_complainant') : e.submitted_by}<span className="block text-xs"><DateDisplay value={e.submitted_at} withTime /></span></td>
                <td className={tdCls}><div className="space-y-2"><CustodyButton url={r('evidence.custody', [e.id])} />{can.review && e.status === 'submitted' && <div className="flex gap-2"><ActionForm url={r('evidence.decide', [e.id])} initial={{ action: 'accept' }} submitLabel={c('accept')} /><ActionForm url={r('evidence.decide', [e.id])} initial={{ action: 'reject' }} submitLabel={c('reject')} fields={[{ name: 'reason', label: t('grievances.common.reason') }]} /></div>}
                    {can.review && e.status !== 'superseded' && <details><summary className="cursor-pointer text-xs">{c('more')}</summary><div className="mt-2 space-y-2"><ActionForm url={r('evidence.classify', [e.id])} initial={{ classification: e.classification }} submitLabel={c('classify')} fields={[{ name: 'classification', label: t('grievances.common.confidentiality'), type: 'select', required: true, options: opts(['normal_confidential', 'restricted', 'highly_restricted'], 'confidentiality') }]} /><ActionForm url={r('evidence.supersede', [e.id])} submitLabel={c('new_version')} fields={[{ name: 'file', label: t('grievances.common.file'), type: 'file', required: true, accept: (options.evidence_extensions ?? []).map(x => `.${x}`).join(',') }, { name: 'description', label: t('grievances.common.description'), type: 'textarea' }]} /></div></details>}</div></td></tr>)}
        </Table> : <Empty>{c('empty')}</Empty>}</Section>
        {can.upload_evidence && <Section title={c('upload_evidence')}><ActionForm url={r('evidence.store')} fields={[
            { name: 'title', label: c('ev_title'), required: true }, { name: 'evidence_type', label: c('ev_type'), type: 'select', required: true, options: opts(options.evidence_types, 'evidence_type') },
            { name: 'classification', label: t('grievances.common.confidentiality'), type: 'select', options: opts(['normal_confidential', 'restricted', 'highly_restricted'], 'confidentiality') },
            { name: 'file', label: t('grievances.common.file'), type: 'file', required: true, accept: (options.evidence_extensions ?? []).map(x => `.${x}`).join(','), help: `${(options.evidence_extensions ?? []).join(', ')} · ≤ ${options.max_file_kb ?? ''} KB` },
            { name: 'description', label: t('grievances.common.description'), type: 'textarea' },
        ]}><p className="text-xs text-gray-500">{c('evidence_help')}</p></ActionForm></Section>}
    </div> });

    if (tabs.information_requests) list.push({ id: 'information', label: c('tab_information'), content: <div className="space-y-4">
        {tabs.information_requests.map(q => <Section key={q.id} title={`${label('information_target', q.requested_from_type)}${q.requested_from_name ? ` — ${q.requested_from_name}` : ''}`} actions={<GPill group="information_status" value={q.status} />}>
            <p className="whitespace-pre-wrap">{q.request_text}</p>
            <p className="mt-2 text-xs text-gray-500">{q.requested_by} · <DateDisplay value={q.requested_at} withTime /> · {t('grievances.common.due')}: <DateDisplay value={q.due_at} />{q.pauses_sla ? ` · ${c('pauses_sla')}` : ''}</p>
            {q.responses.map(x => <div key={x.id} className="mt-3 rounded bg-gray-50 p-3 text-sm dark:bg-slate-800/60"><p className="whitespace-pre-wrap">{x.response_text}</p><p className="mt-1 text-xs text-gray-500">{x.responded_by} · <DateDisplay value={x.responded_at} withTime /></p></div>)}
            {can.review && q.status === 'open' && <div className="mt-4 grid gap-4 md:grid-cols-2"><ActionForm url={r('information.respond', [q.id])} title={c('record_response')} fields={[{ name: 'response_text', label: c('response'), type: 'textarea', required: true }, { name: 'files', label: t('grievances.common.files'), type: 'file', multiple: true }]} /><div className="space-y-2"><ActionForm url={r('information.close', [q.id])} submitLabel={c('close_request')} /><ActionForm url={r('information.close', [q.id])} initial={{ cancel: true }} submitLabel={c('cancel_request')} /></div></div>}
        </Section>)}
        {!tabs.information_requests.length && <Empty>{c('empty')}</Empty>}
        {can.request_information && <Section title={c('new_request')}><ActionForm url={r('information.store')} fields={[
            { name: 'requested_from_type', label: c('requested_from'), type: 'select', required: true, options: opts(options.information_targets, 'information_target') },
            { name: 'requested_from_name', label: c('requested_from_name'), help: c('requested_from_name_help') },
            { name: 'due_at', label: t('grievances.common.due'), type: 'date' },
            { name: 'pauses_sla', label: c('pauses_sla'), type: 'checkbox' },
            { name: 'request_text', label: c('request_text'), type: 'textarea', required: true },
        ]} /></Section>}
    </div> });

    if (tabs.hearings) list.push({ id: 'hearings', label: c('tab_hearings'), content: <div className="space-y-4">
        {tabs.hearings.map(h => <Section key={h.id} title={label('hearing_mode', h.mode)} actions={<GPill group="hearing_status" value={h.status} />}>
            <Details items={[[c('scheduled_at'), <DateDisplay value={h.scheduled_at} withTime />], [c('location'), h.location ?? '—'], [c('duration'), h.duration_minutes ? `${h.duration_minutes} min` : '—'], [c('chair'), employeeName(h.chairperson, locale)], [c('meeting_link'), h.meeting_link ?? '—'], [c('held_at'), <DateDisplay value={h.held_at} withTime />]]} />
            {h.agenda && <p className="mt-3 whitespace-pre-wrap text-sm">{h.agenda}</p>}
            {h.notes && <p className="mt-3 whitespace-pre-wrap text-sm">{h.notes}</p>}
            {h.cancellation_reason && <p className="mt-3 text-sm">{c('cancellation_reason')}: {h.cancellation_reason}</p>}
            <ul className="mt-3 space-y-1 text-sm">{h.participants.map(p => <li key={p.id}>{p.employee ? employeeName(p.employee, locale) : p.name} · {label('participant_role', p.role)}{p.affiliation ? ` · ${p.affiliation}` : ''} · {label('attendance', p.attendance)}{p.contact ? ` · ${p.contact}` : ''}</li>)}</ul>
            {can.manage_hearings && h.status === 'scheduled' && <div className="mt-4"><ActionForm url={r('hearings.update', [h.id])} method="patch" title={c('update_hearing')} fields={[
                { name: 'status', label: t('grievances.common.status'), type: 'select', required: true, options: opts(['scheduled', 'held', 'adjourned', 'cancelled'], 'hearing_status') },
                { name: 'scheduled_at', label: c('scheduled_at'), type: 'datetime' }, { name: 'location', label: c('location') },
                { name: 'cancellation_reason', label: c('cancellation_reason'), type: 'textarea' }, { name: 'notes', label: t('grievances.common.notes'), type: 'textarea' },
            ]} /></div>}
        </Section>)}
        {!tabs.hearings.length && <Empty>{c('empty')}</Empty>}
        {can.manage_hearings && <Section title={c('schedule_hearing')}><ActionForm url={r('hearings.store')} fields={[
            { name: 'scheduled_at', label: c('scheduled_at'), type: 'datetime', required: true },
            { name: 'mode', label: c('mode'), type: 'select', required: true, options: opts(options.hearing_modes, 'hearing_mode') },
            { name: 'duration_minutes', label: c('duration'), type: 'number' }, { name: 'location', label: c('location') },
            { name: 'meeting_link', label: c('meeting_link') }, { name: 'agenda', label: c('agenda'), type: 'textarea' },
        ]}><p className="text-xs text-gray-500">{c('hearing_help')}</p></ActionForm></Section>}
    </div> });

    if (tabs.minutes) list.push({ id: 'minutes', label: c('tab_minutes'), content: <div className="space-y-4">
        {tabs.minutes.map(m => <Section key={m.id} title={fmtVersion(t, m.version_no)} actions={<GPill group="minutes_status" value={m.status} />} description={<><DateDisplay value={m.meeting_date} />{m.amendment_reason ? ` · ${c('amendment_reason')}: ${m.amendment_reason}` : ''}</>}>
            {(['summary', 'discussion', 'resolutions'] as const).filter(k => m[k]).map(k => <div key={k} className="mb-3"><h5 className="text-sm font-semibold">{c(`min_${k}`)}</h5><p className="whitespace-pre-wrap text-sm">{m[k]}</p></div>)}
            {m.attendees?.length ? <p className="text-sm">{c('attendees')}: {m.attendees.join(', ')}</p> : null}
            <p className="mt-2 text-xs text-gray-500">{m.prepared_by}{m.confirmed_by ? ` · ${c('confirmed_by')} ${m.confirmed_by}` : ''}</p>
            {m.status === 'draft' && can.manage_hearings && <div className="mt-4"><MinutesForm url={r('minutes.update', [m.id])} method="patch" initial={{ meeting_date: m.meeting_date, summary: m.summary, discussion: m.discussion ?? '', resolutions: m.resolutions ?? '' }} /></div>}
            {m.status === 'draft' && can.confirm_minutes && <div className="mt-3"><ActionForm url={r('minutes.update', [m.id])} method="patch" initial={{ action: 'confirm' }} submitLabel={c('confirm_minutes')}><p className="text-xs">{c('confirm_minutes_help')}</p></ActionForm></div>}
            {m.status === 'confirmed' && can.manage_hearings && <div className="mt-3"><ActionForm url={r('minutes.update', [m.id])} method="patch" initial={{ action: 'amend' }} submitLabel={c('amend_minutes')} fields={[{ name: 'reason', label: t('grievances.common.reason'), type: 'textarea', required: true }]} /></div>}
        </Section>)}
        {!tabs.minutes.length && <Empty>{c('empty')}</Empty>}
        {can.manage_hearings && <Section title={c('new_minutes')}><MinutesForm url={r('minutes.store')} method="post" hearings={(tabs.hearings ?? []).map(h => ({ value: h.id, label: `${label('hearing_mode', h.mode)} · ${h.scheduled_at.slice(0, 10)}` }))} /></Section>}
    </div> });

    if (tabs.decisions) list.push({ id: 'decision', label: c('tab_decision'), content: <div className="space-y-4">
        <DecisionPanel props={props} />
        {tabs.decisions.filter(d => ['pending_executive_approval', 'resubmitted'].includes(d.status)).map(d => <ApproverActions key={d.id} url={r('decisions.transition', [d.id])} version={d.version_no} />)}
    </div> });

    if (tabs.letters) list.push({ id: 'letters', label: c('tab_letters'), content: <Letters props={props} r={r} /> });

    if (tabs.appeals) list.push({ id: 'appeals', label: c('tab_appeals'), content: <Section title={c('tab_appeals')} flush>{tabs.appeals.length ? <Table head={<>{['status', 'filed', 'deadline', 'stages', 'reason'].map(k => <th key={k} className={thCls}>{c(`ap_${k}`)}</th>)}</>}>
        {tabs.appeals.map(a => <tr key={a.id}><td className={tdCls}><GPill group="appeal_status" value={a.status} /></td><td className={tdCls}><DateDisplay value={a.filed_at} withTime /></td><td className={tdCls}><DateDisplay value={a.deadline_at} /></td><td className={tdCls}>{a.from_stage_no ?? '—'} → {a.to_stage_no ?? '—'}</td><td className={`${tdCls} whitespace-pre-wrap`}>{a.reason}</td></tr>)}
    </Table> : <Empty>{c('empty')}</Empty>}</Section> });

    if (tabs.notes || tabs.tasks) list.push({ id: 'notes', label: c('tab_notes'), content: <div className="grid gap-4 lg:grid-cols-2">
        <Section title={c('notes')} description={c('notes_help')}>
            {can.notes && <ActionForm url={r('notes.store')} submitLabel={c('add_note')} fields={[{ name: 'body', label: c('note'), type: 'textarea', required: true }]} />}
            <ul className="mt-4 space-y-3">{(tabs.notes ?? []).map(n => <li key={n.id} className="rounded bg-gray-50 p-3 text-sm dark:bg-slate-800/60"><p className="whitespace-pre-wrap">{n.body}</p><p className="mt-1 text-xs text-gray-500">{n.author} · <DateDisplay value={n.created_at} withTime /></p></li>)}</ul>
        </Section>
        <Section title={c('tasks')}>
            {can.review && <ActionForm url={r('tasks.store')} submitLabel={c('add_task')} fields={[{ name: 'task_type', label: t('grievances.common.type'), type: 'select', required: true, options: opts(options.task_types, 'task_type') }, { name: 'title', label: c('task_title'), required: true }, { name: 'due_at', label: t('grievances.common.due'), type: 'date' }]} />}
            <ul className="mt-4 space-y-2">{(tabs.tasks ?? []).map(x => <li key={x.id} className="flex flex-wrap items-center justify-between gap-2 border-b pb-2 text-sm dark:border-slate-800"><span>{x.title} · {label('task_type', x.task_type)} · <DateDisplay value={x.due_at} /> <GPill group="task_status" value={x.status} /></span>{can.review && x.status === 'open' && <span className="flex gap-2"><ActionForm url={r('tasks.update', [x.id])} method="patch" initial={{ status: 'done' }} submitLabel={c('mark_done')} /><ActionForm url={r('tasks.update', [x.id])} method="patch" initial={{ status: 'cancelled' }} submitLabel={t('grievances.common.cancel')} /></span>}</li>)}</ul>
        </Section>
    </div> });

    if (tabs.corrective_actions || tabs.referrals) list.push({ id: 'outcomes', label: c('tab_outcomes'), content: <div className="grid gap-4 lg:grid-cols-2">
        <Section title={c('corrective_actions')}>
            <ul className="space-y-3">{(tabs.corrective_actions ?? []).map(a => <li key={a.id} className="rounded border p-3 text-sm dark:border-slate-800"><p className="whitespace-pre-wrap">{a.description}</p><p className="mt-1 text-xs text-gray-500">{nameOf(a.responsible_unit ?? a.responsible_organization, locale)} · <DateDisplay value={a.due_date} /> <GPill group="corrective_status" value={a.status} /></p>{a.completion_notes && <p className="mt-1 text-xs">{a.completion_notes}</p>}
                {can.outcomes && !['completed', 'cancelled'].includes(a.status) && <div className="mt-2"><ActionForm url={r('corrective-actions.update', [a.id])} method="patch" submitLabel={t('grievances.common.save')} fields={[{ name: 'status', label: t('grievances.common.status'), type: 'select', required: true, options: opts(['open', 'in_progress', 'completed', 'cancelled'], 'corrective_status') }, { name: 'completion_notes', label: t('grievances.common.notes'), type: 'textarea' }]} /></div>}</li>)}</ul>
            {can.outcomes && <div className="mt-4"><ActionForm url={r('corrective-actions.store')} title={c('add_corrective')} fields={[{ name: 'description', label: t('grievances.common.description'), type: 'textarea', required: true }, { name: 'due_date', label: t('grievances.common.due'), type: 'date' }, { name: 'decision_id', label: c('tab_decision'), type: 'select', options: (tabs.decisions ?? []).map(d => ({ value: d.id, label: `v${d.version_no} ${label('decision_status', d.status)}` })) }]} /></div>}
        </Section>
        <Section title={c('referrals')} description={c('referral_help')}>
            <ul className="space-y-3">{(tabs.referrals ?? []).map(x => <li key={x.id} className="rounded border p-3 text-sm dark:border-slate-800"><p className="whitespace-pre-wrap">{x.reason}</p><p className="mt-1 text-xs text-gray-500"><DateDisplay value={x.referred_at} /> · {nameOf(x.unit ?? x.organization, locale)} <GPill group="referral_status" value={x.status} />{x.disciplinary_case_reference ? ` · ${x.disciplinary_case_reference}` : ''}</p></li>)}</ul>
            {can.outcomes && viewer.lead && <div className="mt-4"><ActionForm url={r('referrals.store')} title={c('add_referral')} fields={[{ name: 'reason', label: t('grievances.common.reason'), type: 'textarea', required: true }, { name: 'decision_id', label: c('tab_decision'), type: 'select', options: (tabs.decisions ?? []).map(d => ({ value: d.id, label: `v${d.version_no} ${label('decision_status', d.status)}` })) }]} /></div>}
        </Section>
    </div> });

    if (tabs.audit) list.push({ id: 'audit', label: c('tab_audit'), content: <Section title={c('tab_audit')} flush>{tabs.audit.length ? <Table head={<>{['event', 'actor', 'when', 'values'].map(k => <th key={k} className={thCls}>{c(`au_${k}`)}</th>)}</>}>
        {tabs.audit.map(a => <tr key={a.id}><td className={tdCls}>{a.event_type.replace(/^grievance\./, '').replace(/_/g, ' ')}{a.reason && <span className="block text-xs">{a.reason}</span>}</td><td className={tdCls}>{a.actor ?? c('system')}<span className="block text-xs text-gray-500">{a.request_ip}</span></td><td className={tdCls}><DateDisplay value={a.created_at} withTime /></td><td className={tdCls}><details><summary className="cursor-pointer text-xs">{c('show_values')}</summary><pre className="mt-1 max-w-md overflow-x-auto whitespace-pre-wrap text-[11px]">{JSON.stringify({ new: a.new_values, old: a.old_values }, null, 1)}</pre></details></td></tr>)}
    </Table> : <Empty>{c('empty')}</Empty>}</Section> });

    const active = list.find(x => x.id === tab) ?? list[0];

    return <Workspace title={`${g.reference_number}`} backHref={route('grievances.cases.index')}>
        <CaseBanner grievance={g} />
        {viewer.panel_role && <p className="text-sm text-gray-600 dark:text-slate-400">{c('your_role')}: <strong>{label('committee_role', viewer.panel_role)}</strong></p>}
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div className="min-w-0 space-y-4">
                <div role="tablist" aria-label={c('tabs')} className="flex flex-wrap gap-1 border-b border-gray-200 dark:border-slate-800">
                    {list.map(x => <button key={x.id} role="tab" type="button" aria-selected={x.id === active.id} onClick={() => select(x.id)} className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${x.id === active.id ? 'border-[color:var(--color-primary)] text-[color:var(--color-primary)]' : 'border-transparent text-gray-600 hover:text-gray-900 dark:text-slate-400'}`}>{x.label}</button>)}
                </div>
                <div role="tabpanel">{active.content}</div>
            </div>
            <aside className="space-y-3">
                <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{c('workflow')}</h3>
                {actions.length ? actions.map(a => <details key={a.key} className="rounded-lg border border-gray-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-900"><summary className="cursor-pointer text-sm font-medium">{a.title}</summary><div className="mt-3">{a.node}</div></details>) : <p className="text-sm text-gray-500">{c('no_actions')}</p>}
            </aside>
        </div>
    </Workspace>;
}

function fmtVersion(t: (k: string) => string, n: number) {
    return t('grievances.common.version').replace(':n', String(n));
}

function MoveForm({ url, options, reasons }: { url: string; options: { value: string; label: string }[]; reasons: { value: string; label: string }[] }) {
    const { t } = useLocale();
    const [choice, setChoice] = useState(options[0]?.value ?? '');
    const [movement, routeId] = choice.split('|');
    return <div className="space-y-3">
        <label className="block text-sm"><span className="mb-1 block font-medium">{t('grievanceCases.target')}</span><select className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950" value={choice} onChange={e => setChoice(e.target.value)}>{options.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}</select></label>
        <ActionForm key={choice} url={url} initial={{ movement, route_id: routeId }} submitLabel={t('grievanceCases.move_submit')} fields={movement === 'reassigned'
            ? [{ name: 'reason_code', label: t('grievances.common.reason_code'), type: 'select', required: true, options: reasons }, { name: 'reason', label: t('grievances.common.notes'), type: 'textarea' }]
            : [{ name: 'reason', label: t('grievances.common.reason'), type: 'textarea', required: true }]} />
        <p className="text-xs text-gray-500">{t('grievanceCases.move_help')}</p>
    </div>;
}

function RecusalDecision({ url, organizationId }: { url: string; organizationId?: string }) {
    const { t } = useLocale();
    const [employees, setEmployees] = useState<{ value: string; label: string }[]>([]);
    const [q, setQ] = useState('');
    const search = async () => {
        const params = new URLSearchParams({ q }); if (organizationId) params.set('organization_id', organizationId);
        const res = await fetch(`${route('grievances.lookup.employees')}?${params}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (res.ok) setEmployees(((await res.json()) as { id: string; name: string; employee_number: string }[]).map(e => ({ value: e.id, label: `${e.name} (${e.employee_number})` })));
    };
    return <div className="space-y-2">
        <div className="flex gap-2"><input aria-label={t('grievanceCases.find_replacement')} placeholder={t('grievanceCases.find_replacement')} className="w-full rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-950" value={q} onChange={e => setQ(e.target.value)} /><button type="button" className={secondaryBtn} onClick={search}>{t('grievances.common.search')}</button></div>
        <ActionForm url={url} initial={{ approve: true }} submitLabel={t('grievanceCases.decide')} fields={[
            { name: 'approve', label: t('grievanceCases.approve_recusal'), type: 'checkbox' },
            { name: 'replacement_employee_id', label: t('grievanceCases.replacement'), type: 'select', options: employees },
            { name: 'notes', label: t('grievances.common.notes'), type: 'textarea' },
        ]} />
    </div>;
}

function MinutesForm({ url, method, initial = {}, hearings }: { url: string; method: 'post' | 'patch'; initial?: Record<string, string>; hearings?: { value: string; label: string }[] }) {
    const { t } = useLocale();
    const fields: InputField[] = [
        ...(hearings ? [{ name: 'hearing_id', label: t('grievanceCases.tab_hearings'), type: 'select' as const, options: hearings }] : []),
        { name: 'meeting_date', label: t('grievances.common.date'), type: 'date', required: true },
        { name: 'summary', label: t('grievanceCases.min_summary'), type: 'textarea', required: true },
        { name: 'discussion', label: t('grievanceCases.min_discussion'), type: 'textarea' },
        { name: 'resolutions', label: t('grievanceCases.min_resolutions'), type: 'textarea' },
    ];
    return <ActionForm url={url} method={method} initial={initial} fields={fields} submitLabel={t('grievances.common.save')} />;
}

function ApproverActions({ url, version }: { url: string; version: number }) {
    const { t } = useLocale();
    return <Section title={`${t('grievanceCases.approver_actions')} · v${version}`} description={t('grievanceCases.approver_help')}>
        <ActionForm url={url} fields={[
            { name: 'action', label: t('grievances.common.actions'), type: 'select', required: true, options: [
                { value: 'approve', label: t('grievanceWork.approve') }, { value: 'return_for_correction', label: t('grievanceWork.return_for_correction') }, { value: 'reject', label: t('grievanceWork.reject') },
            ] },
            { name: 'comment', label: t('grievances.common.comment'), type: 'textarea', help: t('grievanceCases.comment_required_help') },
        ]} />
    </Section>;
}

function CustodyButton({ url }: { url: string }) {
    const { t } = useLocale(); const label = useEnumLabel();
    const [rows, setRows] = useState<{ action: string; actor: string | null; ip_address: string | null; occurred_at: string }[] | null>(null);
    const load = async () => { const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }); if (res.ok) setRows(await res.json()); };
    return <div>{rows === null ? <button type="button" className="text-xs text-[color:var(--color-primary)] underline" onClick={load}>{t('grievanceCases.custody')}</button>
        : <ul className="space-y-1 text-[11px]">{rows.map((x, i) => <li key={i}>{label('custody_action', x.action)} · {x.actor ?? '—'} · <DateDisplay value={x.occurred_at} withTime /></li>)}</ul>}</div>;
}

function Letters({ props, r }: { props: CaseShowProps; r: (name: string, extra?: (string | number)[]) => string }) {
    const { grievance: g, tabs, options, can } = props;
    const { t } = useLocale(); const label = useEnumLabel();
    const c = (k: string) => t(`grievanceCases.${k}`);
    const letters = tabs.letters ?? [];
    const decisions = (tabs.decisions ?? []).filter(d => ['finalized', 'issued'].includes(d.status));
    return <div className="space-y-4">
        {can.prepare_letters && <Section title={c('generate_letter')}><ActionForm url={r('letters.store')} fields={[
            { name: 'letter_type', label: t('grievances.common.type'), type: 'select', required: true, options: (options.letter_types ?? []).map(v => ({ value: v, label: label('letter_type', v) })) },
            { name: 'language', label: c('language'), type: 'select', required: true, options: (options.letter_languages ?? []).map(v => ({ value: v, label: label('letter_language', v) })) },
            { name: 'decision_id', label: c('tab_decision'), type: 'select', options: decisions.map(d => ({ value: d.id, label: `v${d.version_no} ${d.decision_no ?? ''}` })) },
            { name: 'hearing_id', label: c('tab_hearings'), type: 'select', options: (tabs.hearings ?? []).map(h => ({ value: h.id, label: `${label('hearing_mode', h.mode)} ${h.scheduled_at.slice(0, 10)}` })) },
            { name: 'information_request_id', label: c('tab_information'), type: 'select', options: (tabs.information_requests ?? []).map(q => ({ value: q.id, label: q.request_text.slice(0, 60) })) },
        ]} /></Section>}
        {letters.map(l => <Section key={l.id} title={`${label('letter_type', l.letter_type)} · ${label('letter_language', l.language)}`} actions={<GPill group="letter_status" value={l.status} />}
            description={<>{l.reference_number ?? c('no_reference')}{l.letter_date ? <> · <DateDisplay value={l.letter_date} /></> : null}{l.signatory ? ` · ${c('signed_by')} ${l.signatory} (${label('signature_method', l.signature_method)})` : ''}{l.sealed ? ` · ${c('sealed')}` : ''}</>}>
            <p className="font-medium">{l.subject}</p>
            {l.status === 'draft' && can.prepare_letters ? <div className="mt-3"><ActionForm url={r('letters.update', [l.id])} method="patch" initial={{ subject: l.subject, body: l.body ?? '', visible_to_complainant: l.visible_to_complainant }} fields={[
                { name: 'subject', label: t('grievances.common.subject'), required: true }, { name: 'visible_to_complainant', label: c('visible_to_complainant'), type: 'checkbox' },
                { name: 'body', label: c('body'), type: 'textarea', required: true },
            ]} /></div> : l.body && <p className="mt-2 whitespace-pre-wrap text-sm">{l.body}</p>}
            {l.recipients.length > 0 && <p className="mt-2 text-sm">{l.recipients.map(x => `${label('recipient_kind', x.kind)}: ${x.name}`).join(' · ')}</p>}
            {l.void_reason && <p className="mt-2 text-sm text-red-700">{c('void_reason')}: {l.void_reason}</p>}
            <div className="mt-3 flex flex-wrap items-start gap-2">
                <a className={secondaryBtn} target="_blank" rel="noreferrer" href={r('letters.preview', [l.id])}>{t('grievances.common.preview')}</a>
                {l.has_pdf && <a className={primaryBtn} href={r('letters.download', [l.id])}>{t('grievances.common.download')}</a>}
                {l.status === 'draft' && can.prepare_letters && <ActionForm url={r('letters.transition', [l.id])} initial={{ action: 'finalize' }} submitLabel={c('finalize_letter')} />}
                {l.status === 'finalized' && can.sign_letters && <ActionForm url={r('letters.transition', [l.id])} initial={{ action: 'sign' }} submitLabel={c('sign')} fields={[{ name: 'signature_method', label: c('signature_method'), type: 'select', required: true, options: ['electronic_approval', 'signature_image'].map(v => ({ value: v, label: label('signature_method', v) })), help: c('signature_help') }]} />}
                {l.status === 'signed' && can.seal_letters && !l.sealed && <ActionForm url={r('letters.transition', [l.id])} initial={{ action: 'seal' }} submitLabel={c('apply_seal')} fields={[{ name: 'seal_id', label: c('seal'), type: 'select', required: true, options: (options.seals ?? []).filter(s => s.organization_id === l.organization_id).map(s => ({ value: s.id, label: s.name })) }]} />}
                {l.status === 'signed' && can.issue_letters && <ActionForm url={r('letters.transition', [l.id])} initial={{ action: 'issue' }} submitLabel={c('issue')}><p className="text-xs">{c('issue_help')}</p></ActionForm>}
                {l.status === 'issued' && can.issue_letters && <ActionForm url={r('letters.transition', [l.id])} initial={{ action: 'void' }} submitLabel={c('void')} fields={[{ name: 'reason', label: t('grievances.common.reason'), type: 'textarea', required: true }]} />}
                {l.status === 'voided' && can.prepare_letters && <ActionForm url={r('letters.transition', [l.id])} initial={{ action: 'revise' }} submitLabel={c('revise_letter')} />}
            </div>
            {l.status === 'issued' && <div className="mt-4">
                <h5 className="text-sm font-semibold">{c('dispatches')}</h5>
                <ul className="mt-1 space-y-1 text-sm">{l.dispatches.map(d => <li key={d.id} className="flex flex-wrap items-center gap-2">{label('dispatch_channel', d.channel)} · <GPill group="dispatch_status" value={d.status} />{d.destination ? ` · ${d.destination}` : ''}{d.failure_reason ? ` · ${d.failure_reason}` : ''}{can.issue_letters && !d.acknowledged_at && d.status !== 'failed' && <ActionForm url={r('letters.acknowledge', [l.id, d.id])} submitLabel={c('acknowledge')} />}</li>)}</ul>
                {can.issue_letters && <div className="mt-2"><ActionForm url={r('letters.dispatch', [l.id])} submitLabel={c('dispatch')} fields={[{ name: 'channel', label: c('channel'), type: 'select', required: true, options: (options.dispatch_channels ?? []).map(v => ({ value: v, label: label('dispatch_channel', v) })) }, { name: 'notes', label: t('grievances.common.notes'), maxLength: 255 }]}><p className="text-xs">{c('dispatch_help')}</p></ActionForm></div>}
            </div>}
            {l.pdf_sha256 && <p className="mt-2 font-mono text-[11px] text-gray-500" title={c('hash_help')}>SHA-256 {l.pdf_sha256}</p>}
        </Section>)}
        {!letters.length && <Empty>{c('empty')}</Empty>}
    </div>;
}
