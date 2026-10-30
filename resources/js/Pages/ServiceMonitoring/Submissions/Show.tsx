import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import ExportPanel from '@/Components/serviceMonitoring/ExportPanel';
import { DATE_MARK, errorCls, inputCls, labelCls, panelCls, placeDate, primaryBtn, scrollCls, secondaryBtn, tableCls, tdCls, textareaCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import OfficialTables from '@/Components/serviceMonitoring/OfficialTables';
import SmStatusBadge from '@/Components/serviceMonitoring/SmStatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { ExportRow, OfficialForm, SubmissionProps } from '@/types/serviceMonitoring';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';
import ActionErrors from '@/Components/serviceMonitoring/ActionErrors';

type Props = SubmissionProps & {
    diff: { section: number; field: string; before: number | string | null; after: number | string | null }[];
    form: OfficialForm;
    years: { id: string; ec_year: number }[];
    exports: ExportRow[];
};

/** Read-only report: the official layout, revisions with their differences, history, review and correction actions. */
export default function SubmissionShow({ submission, definition, revisions, history, corrections, diff, form, years, exports, can }: Props): JSX.Element {
    const { sm, name } = useSm();
    const [correcting, setCorrecting] = useState(false);
    const correction = useForm({ reason: '' });
    const decision = useForm({ approve: true, note: '' });
    const pending = corrections.find((c) => c.status === 'pending');
    const fieldLabel = (f: string) => definition.section_2.fields.find((x) => x.key === f)?.label ?? (f === 'rows' ? sm('show.rows') : f);

    function requestCorrection(event: FormEvent) {
        event.preventDefault();
        correction.post(route('service-monitoring.corrections.store', submission.id), { preserveScroll: true, onSuccess: () => { correction.reset(); setCorrecting(false); } });
    }

    function decide(approve: boolean) {
        if (!pending) return;
        decision.transform((d) => ({ ...d, approve }));
        decision.post(route('service-monitoring.corrections.decide', pending.id), { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('show.title', { name: name(submission.organization) })} description={`${submission.period.label} · ${sm(`formType.${submission.form_type}`)}`}
            backHref={route('service-monitoring.submissions.index')}
            actions={<div className="flex flex-wrap gap-2">
                {can.review && <Link className={primaryBtn} href={route('service-monitoring.review.queue')}>{sm('review.title')}</Link>}
                {can.request_correction && !pending && <button type="button" className={secondaryBtn} onClick={() => setCorrecting(true)}>{sm('show.correction')}</button>}
            </div>} />}>
            <Head title={sm('show.title', { name: name(submission.organization) })} />
            <ActionErrors />
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <SmStatusBadge kind="submission" status={submission.status} />
                    {submission.submitted_late && <span className="text-amber-800 dark:text-amber-200">{sm('compliance.late_extension')}</span>}
                    {submission.attestation && <span>{placeDate(sm('show.attested', { name: submission.attestation.name ?? '', date: DATE_MARK }), <LocalizedDateDisplay value={submission.attestation.at} withTime />)}</span>}
                </div>

                {revisions.length > 1 && (
                    <nav aria-label={sm('show.revisions')} className="flex flex-wrap gap-2 text-sm">
                        {revisions.map((r) => (
                            <Link key={r.id} href={route('service-monitoring.submissions.show', r.id)} aria-current={r.id === submission.id ? 'page' : undefined}
                                className={r.id === submission.id ? `${primaryBtn}` : secondaryBtn}>
                                {sm('common.revision', { n: r.revision_no })} · {sm(`status.${r.status}`)}
                            </Link>
                        ))}
                    </nav>
                )}

                {correcting && (
                    <form onSubmit={requestCorrection} className={`${panelCls} space-y-2 p-4`}>
                        <label htmlFor="sm-corr" className={labelCls}>{sm('show.correctionReason')}</label>
                        <textarea id="sm-corr" rows={3} minLength={20} className={textareaCls} value={correction.data.reason} onChange={(e) => correction.setData('reason', e.target.value)} required />
                        {correction.errors.reason && <p className={errorCls}>{correction.errors.reason}</p>}
                        {(correction.errors as Record<string, string>).submission && <p className={errorCls}>{(correction.errors as Record<string, string>).submission}</p>}
                        <div className="flex gap-2">
                            <button type="submit" className={primaryBtn} disabled={correction.processing}>{sm('show.correction')}</button>
                            <button type="button" className={secondaryBtn} onClick={() => setCorrecting(false)}>{sm('common.cancel')}</button>
                        </div>
                    </form>
                )}

                {corrections.length > 0 && (
                    <section className={`${panelCls} space-y-2 p-4`} aria-labelledby="sm-corrections">
                        <h2 id="sm-corrections" className="text-sm font-semibold">{sm('show.corrections')}</h2>
                        <ul className="divide-y divide-(--app-border) text-sm">
                            {corrections.map((c) => (
                                <li key={c.id} className="py-2">
                                    <p>{c.reason}</p>
                                    <p className="text-xs text-(--app-muted-foreground)">{sm(`enums.correction_status.${c.status}`)} · <LocalizedDateDisplay value={c.created_at} />{c.decision_note ? ` · ${c.decision_note}` : ''}</p>
                                    {c.new_submission_id && <Link className="text-xs underline" href={route('service-monitoring.submissions.edit', c.new_submission_id)}>{sm('common.open')}</Link>}
                                </li>
                            ))}
                        </ul>
                        {pending && can.decide_correction && (
                            <div className="grid gap-2 sm:grid-cols-3">
                                <div className="sm:col-span-3">
                                    <label htmlFor="sm-note" className={labelCls}>{sm('show.decisionNote')}</label>
                                    <input id="sm-note" className={inputCls} value={decision.data.note} onChange={(e) => decision.setData('note', e.target.value)} />
                                </div>
                                <button type="button" className={primaryBtn} disabled={decision.processing} onClick={() => decide(true)}>{sm('show.approve')}</button>
                                <button type="button" className={secondaryBtn} disabled={decision.processing} onClick={() => decide(false)}>{sm('show.reject')}</button>
                            </div>
                        )}
                    </section>
                )}

                {diff.length > 0 && (
                    <section aria-labelledby="sm-diff" className="space-y-2">
                        <h2 id="sm-diff" className="text-sm font-semibold">{sm('show.changes')}</h2>
                        <div className={scrollCls}>
                            <table className={tableCls}>
                                <thead><tr>{['common.quarter', 'show.field', 'show.before', 'show.after'].map((k) => <th key={k} scope="col" className={thCls}>{k === 'common.quarter' ? sm('edit.section', { n: '' }) : sm(k)}</th>)}</tr></thead>
                                <tbody>{diff.map((d, i) => <tr key={i}><td className={tdCls}>{d.section}</td><td className={tdCls}>{fieldLabel(d.field)}</td><td className={tdCls}>{d.before ?? '—'}</td><td className={tdCls}>{d.after ?? '—'}</td></tr>)}</tbody>
                            </table>
                        </div>
                    </section>
                )}

                <section aria-labelledby="sm-form" className="space-y-4">
                    <h2 id="sm-form" className="text-sm font-semibold">{sm('show.officialForm')}</h2>
                    {(['section_1', 'summary', 'section_2', 'section_3', 'section_4'] as const).map((key) => (
                        <OfficialTables key={key} idPrefix={`show-${key}`} tables={form.sections[key]} />
                    ))}
                </section>

                {can.export && (
                    <ExportPanel scopeType="submission" scopeId={submission.id} fiscalYearId={years[0].id} quarter={submission.period.quarter} range="quarter" canNamed={can.view_named} exports={exports} />
                )}

                <section className={`${panelCls} p-4`} aria-labelledby="sm-history">
                    <h2 id="sm-history" className="mb-2 text-sm font-semibold">{sm('show.history')}</h2>
                    <ol className="space-y-1 text-sm">
                        {history.map((h, i) => (
                            <li key={i}>
                                <LocalizedDateDisplay value={h.at} withTime /> · {h.from ? `${sm(`status.${h.from}`)} → ` : ''}{sm(`status.${h.to}`)} · {h.actor ?? '—'}{h.reason ? ` — ${h.reason}` : ''}
                            </li>
                        ))}
                    </ol>
                </section>
                {submission.status === 'superseded' && (
                    <button type="button" className={secondaryBtn} onClick={() => router.get(route('service-monitoring.submissions.show', revisions[revisions.length - 1].id))}>{sm('show.revisions')}</button>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
