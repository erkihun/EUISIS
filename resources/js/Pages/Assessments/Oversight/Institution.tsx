import { CoverageBar, ExportButtons, GenderTable, Kpi, KpiGroup, OUTCOMES, OversightLayout, SeverityBadge, StatusBadge, named, oversightHref, pct, type Distribution, type Metrics, type ShellProps } from '@/Components/assessmentOversight/shell';
import { Section, Table, dangerBtn, inputCls, primaryBtn, secondaryBtn, smallBtn, tdCls, thCls } from '@/Components/performance/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Unit = Metrics & { unit: { id: string; name_en: string; name_am: string | null } | null };
type FormUse = { version_id: string; code: string | null; form_en: string | null; form_am: string | null; version_no: number | null; version_status: string | null; targets: string[]; expected: number; assigned: number; assessed: number; unassessed: number; completion_percent: string | null };
type Peer = { version_id: string; name_en: string; name_am: string | null; version_no: number; records: number; required: number; assigned: number; completed: number; aggregate_ready: number; aggregate_not_ready: number };
type ReasonRow = { code: string | null; name_en: string | null; name_am: string | null; n: number; source?: string };
type HistoryRow = {
    id: string; revision_no: number; status: string; eligible_count: number; assessed_count: number; unassessed_count: number; coverage_percent: string | null;
    return_reason: string | null; sign_off_note: string | null; submitted_at: string | null; verified_at: string | null; finalized_at: string | null; submitter: string | null;
    events: Array<{ action: string; actor: string | null; comment: string | null; at: string | null }>;
};
type Exclusion = {
    id: string; status: string; note: string; decision_note: string | null; eligibility_id: string;
    employee: { employee_number: string; full_name: string; name_en: string | null } | null; reason: { code: string; name_en: string; name_am: string | null } | null;
    requester: string | null; decider: string | null; requested_at: string | null; decided_at: string | null; can: { decide: boolean; withdraw: boolean };
};

type Props = ShellProps & {
    organization: { id: string; code: string; name_en: string; name_am: string | null };
    participation: { status: string; submission_status: string; exclusion_reason: string | null };
    totals: Metrics; units: Unit[]; forms: FormUse[]; peers: Peer[]; distribution: Distribution | null;
    reasons: { exceptions: ReasonRow[]; reported: ReasonRow[]; excluded: ReasonRow[] };
    issues: null | { blocking: number; warning: number; info: number; rules: Record<string, { severity: string; count: number }>; cycle: Array<{ code: string; severity: string }> };
    history: HistoryRow[]; changedAfterFinalization: boolean;
    readiness: null | { ready: boolean; checks: Array<{ code: string; ok: boolean; detail?: unknown }> };
    exclusions: Exclusion[];
    actions: { submit: boolean; return: boolean; reject: boolean; verify: boolean; finalize: boolean; requestExclusion: boolean };
    latest: { id: string; status: string; revision_no: number } | null;
};

/** Institution Assessment Summary: City → Institution → Unit → Employee, each level scoped on the server. */
export default function OversightInstitution(props: Props) {
    const { t, locale } = useLocale();
    const { cycle, can, organization, totals, actions, latest } = props;
    const submitForm = useForm({ organization_id: organization.id, note: '' });
    const [comment, setComment] = useState('');
    const filters = { organization_id: organization.id };

    function move(action: 'return' | 'reject' | 'verify' | 'finalize') {
        if (!latest) return;
        router.post(route('assessment-oversight.submissions.move', { submission: latest.id, action }), { comment }, { preserveScroll: true, onSuccess: () => setComment('') });
    }

    return (
        <OversightLayout title={named(organization, locale)} description={`${organization.code} · ${t('assessmentOversight.institutionSummary')}`} active="institutions" shell={props}
            actions={cycle && can.export ? <ExportButtons report="consolidated" cycle={cycle} filters={filters} /> : undefined}>
            {cycle && (
                <>
                    {props.participation.status === 'excluded' && <p role="note" className="rounded-panel border border-gray-200 bg-gray-50 px-4 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">{t('assessmentOversight.participationStatuses.excluded')}: {props.participation.exclusion_reason}</p>}
                    {props.changedAfterFinalization && <p role="alert" className="rounded-panel border border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">{t('assessmentOversight.changedAfterFinalization')}</p>}

                    <KpiGroup title={t('assessmentOversight.sections.overview')}>
                        <Kpi label={t('assessmentOversight.eligible')} value={totals.eligible} href={can.employees ? oversightHref('assessment-oversight.employees', cycle, filters) : undefined} />
                        <Kpi label={t('assessmentOversight.assessed')} value={totals.assessed} />
                        <Kpi label={t('assessmentOversight.unassessed')} value={totals.unassessed} tone="warning" href={can.employees ? oversightHref('assessment-oversight.employees', cycle, { ...filters, outcome: 'unassessed' }) : undefined} />
                        <Kpi label={t('assessmentOversight.coverage')} value={totals.coverage_percent} suffix="%" />
                        <Kpi label={t('assessmentOversight.excluded')} value={totals.excluded} />
                        <Kpi label={t('assessmentOversight.submissionStatus')} value={t(`assessmentOversight.submissionStatuses.${props.participation.submission_status}`)} />
                    </KpiGroup>

                    <div className="grid gap-4 xl:grid-cols-2">
                        <Section title={t('assessmentOversight.sections.statuses')}>
                            <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                                {OUTCOMES.map((o) => (
                                    <li key={o} className="flex items-center justify-between py-2">
                                        <StatusBadge group="outcomes" value={o} />
                                        {can.employees && totals.outcomes[o] > 0
                                            ? <Link className="tabular-nums hover:underline" href={oversightHref('assessment-oversight.employees', cycle, { ...filters, outcome: o })}>{totals.outcomes[o]}</Link>
                                            : <span className="tabular-nums">{totals.outcomes[o] ?? 0}</span>}
                                    </li>
                                ))}
                            </ul>
                        </Section>
                        {totals.gender && <Section title={t('assessmentOversight.sections.gender')}><GenderTable gender={totals.gender} /></Section>}
                    </div>

                    <Section title={t('assessmentOversight.sections.units')} description={t('assessmentOversight.unitsHelp')} flush>
                        <Table head={<>
                            <th className={thCls}>{t('assessmentOversight.unit')}</th>
                            <th className={thCls}>{t('assessmentOversight.eligible')}</th>
                            <th className={thCls}>{t('assessmentOversight.assessed')}</th>
                            <th className={thCls}>{t('assessmentOversight.unassessed')}</th>
                            <th className={thCls}>{t('assessmentOversight.coverage')}</th>
                        </>}>
                            {props.units.map((u) => (
                                <tr key={u.group_key ?? 'none'}>
                                    <td className={tdCls}>{can.employees && u.unit
                                        ? <Link className="hover:underline" href={oversightHref('assessment-oversight.employees', cycle, { ...filters, organization_unit_id: u.unit.id })}>{named(u.unit, locale)}</Link>
                                        : (u.unit ? named(u.unit, locale) : t('assessmentOversight.noUnit'))}</td>
                                    <td className={`${tdCls} tabular-nums`}>{u.eligible}</td>
                                    <td className={`${tdCls} tabular-nums`}>{u.assessed}</td>
                                    <td className={`${tdCls} tabular-nums`}>{u.unassessed}</td>
                                    <td className={tdCls}><CoverageBar value={u.coverage_percent} /></td>
                                </tr>
                            ))}
                        </Table>
                    </Section>

                    <Section title={t('assessmentOversight.sections.forms')} description={t('assessmentOversight.formsHelp')} flush>
                        <Table head={<>
                            <th className={thCls}>{t('assessmentOversight.form')}</th>
                            <th className={thCls}>{t('assessmentOversight.targetGroup')}</th>
                            <th className={thCls}>{t('assessmentOversight.expected')}</th>
                            <th className={thCls}>{t('assessmentOversight.kpi.assigned')}</th>
                            <th className={thCls}>{t('assessmentOversight.assessed')}</th>
                            <th className={thCls}>{t('assessmentOversight.unassessed')}</th>
                            <th className={thCls}>{t('assessmentOversight.completion')}</th>
                        </>}>
                            {props.forms.map((f) => (
                                <tr key={f.version_id}>
                                    <td className={tdCls}>{(locale === 'am' && f.form_am) || f.form_en} <span className="text-xs text-gray-500">v{f.version_no} · {f.version_status}</span></td>
                                    <td className={`${tdCls} text-xs`}>{f.targets.map((x) => t(`assessmentOversight.targets.${x.split(':')[1]}`) + (x.startsWith('exclude') ? ` (${t('assessmentOversight.targets.exclude')})` : '')).join(', ') || '—'}</td>
                                    <td className={`${tdCls} tabular-nums`}>{f.expected}</td>
                                    <td className={`${tdCls} tabular-nums`}>{f.assigned}</td>
                                    <td className={`${tdCls} tabular-nums`}>{f.assessed}</td>
                                    <td className={`${tdCls} tabular-nums`}>{f.unassessed}</td>
                                    <td className={tdCls}>{pct(f.completion_percent)}</td>
                                </tr>
                            ))}
                        </Table>
                    </Section>

                    {props.peers.length > 0 && (
                        <Section title={t('assessmentOversight.sections.peers')} flush>
                            <Table head={<>
                                <th className={thCls}>{t('assessmentOversight.form')}</th>
                                <th className={thCls}>{t('assessmentOversight.peers.required')}</th>
                                <th className={thCls}>{t('assessmentOversight.peers.assigned')}</th>
                                <th className={thCls}>{t('assessmentOversight.peers.completed')}</th>
                                <th className={thCls}>{t('assessmentOversight.peers.ready')}</th>
                                <th className={thCls}>{t('assessmentOversight.peers.notReady')}</th>
                            </>}>
                                {props.peers.map((p) => (
                                    <tr key={p.version_id}>
                                        <td className={tdCls}>{(locale === 'am' && p.name_am) || p.name_en} v{p.version_no}</td>
                                        <td className={`${tdCls} tabular-nums`}>{p.required}</td>
                                        <td className={`${tdCls} tabular-nums`}>{p.assigned}</td>
                                        <td className={`${tdCls} tabular-nums`}>{p.completed}</td>
                                        <td className={`${tdCls} tabular-nums`}>{p.aggregate_ready}</td>
                                        <td className={`${tdCls} tabular-nums`}>{p.aggregate_not_ready}</td>
                                    </tr>
                                ))}
                            </Table>
                        </Section>
                    )}

                    <div className="grid gap-4 xl:grid-cols-2">
                        {props.distribution && (
                            <Section title={t('assessmentOversight.sections.distribution')} description={props.distribution.policy ? `${named(props.distribution.policy, locale)} v${props.distribution.policy.version_no}` : t('assessmentOversight.noBandPolicy')}>
                                <ul className="divide-y divide-gray-100 text-sm dark:divide-slate-800">
                                    {props.distribution.bands.map((b) => (
                                        <li key={b.code} className="flex justify-between py-2"><span>{(locale === 'am' && b.label_am) || b.label_en}</span><span className="tabular-nums">{b.count} · {pct(b.percent)}</span></li>
                                    ))}
                                    {props.distribution.unclassified > 0 && <li className="flex justify-between py-2 text-amber-700"><span>{t('assessmentOversight.unclassified')}</span><span>{props.distribution.unclassified}</span></li>}
                                </ul>
                            </Section>
                        )}
                        <Section title={t('assessmentOversight.sections.reasons')}>
                            {(['exceptions', 'reported', 'excluded'] as const).map((group) => props.reasons[group].length > 0 && (
                                <div key={group} className="mb-3">
                                    <h4 className="text-xs font-semibold uppercase text-gray-500">{t(`assessmentOversight.reasonGroups.${group}`)}</h4>
                                    <ul className="mt-1 text-sm">
                                        {props.reasons[group].map((r) => <li key={`${group}-${r.code}-${r.source ?? ''}`} className="flex justify-between py-1"><span>{(locale === 'am' && r.name_am) || r.name_en || r.code || t('assessmentOversight.noReason')}</span><span className="tabular-nums">{r.n}</span></li>)}
                                    </ul>
                                </div>
                            ))}
                            {props.reasons.exceptions.length + props.reasons.reported.length + props.reasons.excluded.length === 0 && <p className="text-sm text-gray-500">{t('assessmentOversight.noData')}</p>}
                        </Section>
                    </div>

                    {props.issues && (
                        <Section title={t('assessmentOversight.sections.dataQuality')} actions={<Link className={smallBtn} href={oversightHref('assessment-oversight.data-quality', cycle, filters)}>{t('assessmentOversight.openList')}</Link>}>
                            <div className="flex flex-wrap gap-2">
                                {[...props.issues.cycle.map((i) => ({ code: i.code, severity: i.severity, count: 1 })), ...Object.entries(props.issues.rules).filter(([, r]) => r.count > 0).map(([code, r]) => ({ code, ...r }))].map((i) => (
                                    <span key={i.code} className="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-2 py-1 text-xs dark:border-slate-700"><SeverityBadge severity={i.severity} />{t(`assessmentOversight.rules.${i.code}`)} <b className="tabular-nums">{i.count}</b></span>
                                ))}
                                {props.issues.blocking + props.issues.warning + props.issues.info === 0 && props.issues.cycle.length === 0 && <p className="text-sm text-emerald-700">{t('assessmentOversight.noIssues')}</p>}
                            </div>
                        </Section>
                    )}

                    <Section title={t('assessmentOversight.sections.submission')} description={t('assessmentOversight.submissionHelp')}>
                        {props.readiness && (
                            <div className="mb-4">
                                <h4 className="text-xs font-semibold uppercase text-gray-500">{t('assessmentOversight.readiness.title')}</h4>
                                <ul className="mt-2 grid gap-1 text-sm sm:grid-cols-2">
                                    {props.readiness.checks.map((c) => <li key={c.code} className={c.ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'}>{c.ok ? '✓' : '✗'} {t(`assessmentOversight.readiness.${c.code}`)}</li>)}
                                </ul>
                            </div>
                        )}
                        {actions.submit && (
                            <form className="mb-4 grid gap-2 sm:max-w-xl" onSubmit={(e) => { e.preventDefault(); submitForm.post(route('assessment-oversight.submissions.store', cycle.id), { preserveScroll: true }); }}>
                                <label htmlFor="ao-note" className="text-sm font-medium">{t('assessmentOversight.signOffNote')}</label>
                                <textarea id="ao-note" rows={2} className={inputCls} value={submitForm.data.note} onChange={(e) => submitForm.setData('note', e.target.value)} />
                                {submitForm.errors && Object.values(submitForm.errors).map((e) => <p key={e} className="text-sm text-red-600">{e}</p>)}
                                <div><button className={primaryBtn} disabled={submitForm.processing}>{t('assessmentOversight.submitSummary')}</button></div>
                                <p className="text-xs text-gray-500">{t('assessmentOversight.submitHelp')}</p>
                            </form>
                        )}
                        {(actions.return || actions.reject || actions.verify || actions.finalize) && latest && (
                            <div className="mb-4 grid gap-2 sm:max-w-xl">
                                <label htmlFor="ao-comment" className="text-sm font-medium">{t('assessmentOversight.reviewComment')}</label>
                                <textarea id="ao-comment" rows={2} className={inputCls} value={comment} onChange={(e) => setComment(e.target.value)} />
                                <div className="flex flex-wrap gap-2">
                                    {actions.verify && <button type="button" className={primaryBtn} onClick={() => move('verify')}>{t('assessmentOversight.actions.verify')}</button>}
                                    {actions.finalize && <button type="button" className={primaryBtn} onClick={() => move('finalize')}>{t('assessmentOversight.actions.finalize')}</button>}
                                    {actions.return && <button type="button" className={secondaryBtn} disabled={!comment.trim()} onClick={() => move('return')}>{t('assessmentOversight.actions.return')}</button>}
                                    {actions.reject && <button type="button" className={dangerBtn} disabled={!comment.trim()} onClick={() => move('reject')}>{t('assessmentOversight.actions.reject')}</button>}
                                </div>
                            </div>
                        )}
                        <h4 className="text-xs font-semibold uppercase text-gray-500">{t('assessmentOversight.sections.history')}</h4>
                        {props.history.length === 0 ? <p className="mt-1 text-sm text-gray-500">{t('assessmentOversight.noSubmissions')}</p> : (
                            <ol className="mt-2 space-y-3">
                                {props.history.map((h) => (
                                    <li key={h.id} className="rounded-lg border border-gray-200 p-3 text-sm dark:border-slate-700">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <b>{t('assessmentOversight.revision')} {h.revision_no}</b><StatusBadge group="submissionStatuses" value={h.status} />
                                            <span className="text-xs text-gray-500">{h.eligible_count} / {h.assessed_count} / {h.unassessed_count} · {pct(h.coverage_percent)}</span>
                                        </div>
                                        {h.return_reason && <p className="mt-1 text-amber-800 dark:text-amber-300">{h.return_reason}</p>}
                                        <ul className="mt-2 space-y-0.5 text-xs text-gray-600 dark:text-slate-400">
                                            {h.events.map((e, i) => <li key={i}>{e.at ? <LocalizedDateDisplay value={e.at} withTime /> : null} · {t(`assessmentOversight.events.${e.action}`)} · {e.actor ?? t('assessmentOversight.system')}{e.comment ? ` — ${e.comment}` : ''}</li>)}
                                        </ul>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </Section>

                    {props.exclusions.length > 0 && <Exclusions rows={props.exclusions} />}
                </>
            )}
        </OversightLayout>
    );
}

function Exclusions({ rows }: { rows: Exclusion[] }) {
    const { t, locale } = useLocale();
    const [notes, setNotes] = useState<Record<string, string>>({});
    const decide = (id: string, approve: boolean) => router.post(route('assessment-oversight.exclusions.decide', id), { approve, note: notes[id] ?? '' }, { preserveScroll: true });
    return (
        <Section title={t('assessmentOversight.sections.exclusions')} description={t('assessmentOversight.exclusionsHelp')} flush>
            <Table head={<>
                <th className={thCls}>{t('assessmentOversight.employee')}</th>
                <th className={thCls}>{t('assessmentOversight.reason')}</th>
                <th className={thCls}>{t('assessmentOversight.status')}</th>
                <th className={thCls}>{t('assessmentOversight.requestedBy')}</th>
                <th className={thCls}><span className="sr-only">{t('assessmentOversight.actionsLabel')}</span></th>
            </>}>
                {rows.map((x) => (
                    <tr key={x.id}>
                        <td className={tdCls}>{x.employee?.employee_number} · {(locale === 'am' ? x.employee?.full_name : x.employee?.name_en) || x.employee?.full_name}</td>
                        <td className={tdCls}>{x.reason ? ((locale === 'am' && x.reason.name_am) || x.reason.name_en) : '—'}<div className="text-xs text-gray-500">{x.note}</div></td>
                        <td className={tdCls}>{t(`assessmentOversight.exclusionStatuses.${x.status}`)}{x.decision_note && <div className="text-xs text-gray-500">{x.decision_note}</div>}</td>
                        <td className={tdCls}>{x.requester}{x.requested_at && <div className="text-xs text-gray-500"><LocalizedDateDisplay value={x.requested_at} /></div>}</td>
                        <td className={tdCls}>
                            {x.can.decide && (
                                <div className="flex flex-wrap items-center gap-2">
                                    <input aria-label={t('assessmentOversight.decisionNote')} placeholder={t('assessmentOversight.decisionNote')} className="rounded-lg border border-gray-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-950" value={notes[x.id] ?? ''} onChange={(e) => setNotes({ ...notes, [x.id]: e.target.value })} />
                                    <button type="button" className={smallBtn} onClick={() => decide(x.id, true)}>{t('assessmentOversight.actions.approve')}</button>
                                    <button type="button" className={smallBtn} disabled={!(notes[x.id] ?? '').trim()} onClick={() => decide(x.id, false)}>{t('assessmentOversight.actions.reject')}</button>
                                </div>
                            )}
                            {x.can.withdraw && <button type="button" className={smallBtn} onClick={() => router.post(route('assessment-oversight.exclusions.withdraw', x.id), {}, { preserveScroll: true })}>{t('assessmentOversight.actions.withdraw')}</button>}
                        </td>
                    </tr>
                ))}
            </Table>
        </Section>
    );
}
