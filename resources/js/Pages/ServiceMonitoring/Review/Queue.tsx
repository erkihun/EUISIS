import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import { errorCls, inputCls, labelCls, panelCls, primaryBtn, scrollCls, secondaryBtn, tableCls, tdCls, textareaCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { FormType, Named, ReportingLevel, SectionNumber } from '@/types/serviceMonitoring';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';
import ActionErrors from '@/Components/serviceMonitoring/ActionErrors';

type Pending = { id: string; level: ReportingLevel; form_type: FormType; revision_no: number; submitted_at: string | null; submitted_late: boolean; period_label: string; organization: Named; can_review: boolean };
type Correction = { id: string; reason: string; requested_by: string | null; created_at: string | null; submission_id: string; period_label: string; organization: Named };
type Comment = { section: SectionNumber; line_reference: string; body: string };

/** Submitted reports waiting for this reviewer: accept, or return with a reason and line comments (D8). */
export default function ReviewQueue({ pending, corrections }: { pending: Pending[]; corrections: Correction[] }): JSX.Element {
    const { sm, name } = useSm();
    const [returning, setReturning] = useState<Pending | null>(null);
    const form = useForm<{ reason: string; comments: Comment[] }>({ reason: '', comments: [] });

    function send(event: FormEvent) {
        event.preventDefault();
        if (!returning) return;
        form.post(route('service-monitoring.review.return', returning.id), { onSuccess: () => { form.reset(); setReturning(null); } });
    }

    const setComment = (index: number, changes: Partial<Comment>) => form.setData('comments', form.data.comments.map((c, i) => (i === index ? { ...c, ...changes } : c)));

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('review.title')} description={sm('review.description')} />}>
            <Head title={sm('review.title')} />
            <ActionErrors />
            <div className="space-y-4">
                {pending.length === 0 ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('review.empty')}</p> : (
                    <div className={scrollCls}>
                        <table className={tableCls}>
                            <thead><tr>{['common.institution', 'common.level', 'common.period', 'common.submittedAt', 'common.actions'].map((k) => <th key={k} scope="col" className={thCls}>{sm(k)}</th>)}</tr></thead>
                            <tbody>
                                {pending.map((p) => (
                                    <tr key={p.id}>
                                        <td className={tdCls}><Link className="underline" href={route('service-monitoring.submissions.show', p.id)}>{name(p.organization)}</Link></td>
                                        <td className={tdCls}>{sm(`level.${p.level}`)} · {sm(`formType.${p.form_type}`)}</td>
                                        <td className={tdCls}>{p.period_label}{p.revision_no > 1 ? ` · ${sm('common.revision', { n: p.revision_no })}` : ''}</td>
                                        <td className={tdCls}><LocalizedDateDisplay value={p.submitted_at} withTime />{p.submitted_late && <span className="ms-1 text-xs">({sm('common.late')})</span>}</td>
                                        <td className={tdCls}>
                                            {p.can_review && (
                                                <div className="flex flex-wrap gap-2">
                                                    <button type="button" className={primaryBtn} onClick={() => router.post(route('service-monitoring.review.accept', p.id))}>{sm('review.accept')}</button>
                                                    <button type="button" className={secondaryBtn} onClick={() => setReturning(p)}>{sm('review.return')}</button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                <section className={`${panelCls} space-y-2 p-4`} aria-labelledby="sm-corr">
                    <h2 id="sm-corr" className="text-sm font-semibold">{sm('review.corrections')}</h2>
                    {corrections.length === 0 ? <p className="text-sm text-(--app-muted-foreground)">{sm('review.noCorrections')}</p> : (
                        <ul className="divide-y divide-(--app-border) text-sm">
                            {corrections.map((c) => (
                                <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                                    <span>{name(c.organization)} · {c.period_label} · {c.reason} <span className="text-xs text-(--app-muted-foreground)">({c.requested_by})</span></span>
                                    <Link className={secondaryBtn} href={route('service-monitoring.submissions.show', c.submission_id)}>{sm('show.decide')}</Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <Modal show={returning !== null} onClose={() => setReturning(null)} maxWidth="xl">
                <form onSubmit={send} className="space-y-3 p-5" aria-labelledby="sm-return-title">
                    <h2 id="sm-return-title" className="text-base font-semibold">{sm('review.returnTitle')}{returning ? `: ${name(returning.organization)}` : ''}</h2>
                    <div>
                        <label htmlFor="sm-return-reason" className={labelCls}>{sm('review.returnReason')}</label>
                        <textarea id="sm-return-reason" rows={3} minLength={10} autoFocus className={textareaCls} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} required />
                        {form.errors.reason && <p className={errorCls}>{form.errors.reason}</p>}
                    </div>
                    {form.data.comments.map((c, i) => (
                        <fieldset key={i} className="grid gap-2 rounded-panel border border-(--app-border) p-2 sm:grid-cols-4">
                            <legend className="text-xs">{sm('review.addComment')} {i + 1}</legend>
                            <div>
                                <label htmlFor={`sm-c-sec-${i}`} className={labelCls}>{sm('review.commentSection')}</label>
                                <select id={`sm-c-sec-${i}`} className={inputCls} value={c.section} onChange={(e) => setComment(i, { section: Number(e.target.value) as SectionNumber })}>
                                    {[1, 2, 3, 4].map((n) => <option key={n} value={n}>{sm('edit.section', { n })}</option>)}
                                </select>
                            </div>
                            <div>
                                <label htmlFor={`sm-c-line-${i}`} className={labelCls}>{sm('review.commentLine')}</label>
                                <input id={`sm-c-line-${i}`} className={inputCls} value={c.line_reference} onChange={(e) => setComment(i, { line_reference: e.target.value })} />
                            </div>
                            <div className="sm:col-span-2">
                                <label htmlFor={`sm-c-body-${i}`} className={labelCls}>{sm('review.commentBody')}</label>
                                <input id={`sm-c-body-${i}`} className={inputCls} value={c.body} onChange={(e) => setComment(i, { body: e.target.value })} required />
                            </div>
                        </fieldset>
                    ))}
                    <button type="button" className={secondaryBtn} onClick={() => form.setData('comments', [...form.data.comments, { section: 1, line_reference: '', body: '' }])}>{sm('review.addComment')}</button>
                    <div className="flex justify-end gap-2">
                        <button type="button" className={secondaryBtn} onClick={() => setReturning(null)}>{sm('common.cancel')}</button>
                        <button type="submit" className={primaryBtn} disabled={form.processing}>{sm('review.return')}</button>
                    </div>
                </form>
            </Modal>
        </AuthenticatedLayout>
    );
}
