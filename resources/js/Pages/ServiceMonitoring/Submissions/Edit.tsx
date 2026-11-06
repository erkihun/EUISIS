import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import Modal from '@/Components/Modal';
import PageHeader from '@/Components/PageHeader';
import Section1Panel from '@/Components/serviceMonitoring/edit/Section1Panel';
import Section2Panel from '@/Components/serviceMonitoring/edit/Section2Panel';
import Section3Panel from '@/Components/serviceMonitoring/edit/Section3Panel';
import Section4Panel from '@/Components/serviceMonitoring/edit/Section4Panel';
import CustomSectionsPanel from '@/Components/serviceMonitoring/edit/CustomSectionsPanel';
import { DATE_MARK, errorCls, labelCls, panelCls, placeDate, primaryBtn, secondaryBtn, useSm } from '@/Components/serviceMonitoring/helpers';
import SmStatusBadge from '@/Components/serviceMonitoring/SmStatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { CUSTOM_SECTION, type SectionNumber, type SubmissionProps, type Suggestion } from '@/types/serviceMonitoring';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useId, useRef, useState, type FormEvent, type JSX, type KeyboardEvent } from 'react';

const OFFICIAL: SectionNumber[] = [1, 2, 3, 4];

/** A tab: an official section, or the form template's additional sections. */
type Tab = SectionNumber | typeof CUSTOM_SECTION;

/**
 * The entry screen: one tab per official form section, each with its "የለም"
 * toggle, completeness mark and the reviewer's comments; autosaving figures;
 * the attestation dialog with the declaration and the stamped copy (D10, D11).
 */
export default function SubmissionEdit(props: SubmissionProps & { suggestions?: Suggestion[] }): JSX.Element {
    const { submission, definition, section1, section2, section3, section4, problems, completeness, comments, attachments, declaration, require_stamped_copy, can, suggestions, history, custom } = props;
    // The report's form template may leave out official sections and add its own (docs/service-monitoring.md §Form templates).
    const official = OFFICIAL.filter((n) => (definition.enabled_sections ?? OFFICIAL).includes(n));
    const hasCustom = (definition.custom?.sections.length ?? 0) > 0;
    const SECTIONS: Tab[] = hasCustom ? [...official, CUSTOM_SECTION] : official;
    // The reviewer's reason for the latest return, shown with the banner.
    const returnReason = [...history].reverse().find((h) => h.to === 'returned')?.reason ?? null;
    const { sm, name } = useSm();
    const id = useId();
    const [tab, setTab] = useState<Tab>(SECTIONS[0] ?? 1);
    const [attesting, setAttesting] = useState(false);
    const tabRefs = useRef<(HTMLButtonElement | null)[]>([]);
    const errors = usePage().props.errors as Record<string, string>;
    const attest = useForm({ declaration: false });
    const upload = useForm<{ file: File | null }>({ file: null });
    const editable = can.edit;

    function toggleNothing(section: Tab, nothing: boolean) {
        router.post(route('service-monitoring.submissions.nothing', submission.id), { section, nothing }, { preserveScroll: true });
    }

    function onTabKey(event: KeyboardEvent<HTMLButtonElement>, index: number) {
        if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
        const next = (index + (event.key === 'ArrowRight' ? 1 : SECTIONS.length - 1)) % SECTIONS.length;
        setTab(SECTIONS[next]);
        tabRefs.current[next]?.focus();
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        attest.post(route('service-monitoring.submissions.submit', submission.id), { onSuccess: () => setAttesting(false) });
    }

    function sendFile(event: FormEvent) {
        event.preventDefault();
        upload.post(route('service-monitoring.submissions.stamped-copy', submission.id), { forceFormData: true, preserveScroll: true, onSuccess: () => upload.reset() });
    }

    const sectionTitle = (n: Tab) => n === CUSTOM_SECTION ? sm('templates.customTab') : [definition.section_1.title, definition.section_2.title, definition.section_3.title, definition.section_4.title][n - 1];
    const allProblems = Object.values(problems).flat();
    const serverErrors = Object.entries(errors).filter(([key]) => key.startsWith('section_') || ['deadline', 'period', 'submission', 'stamped_copy', 'attest', 'custom'].includes(key));

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={sm('edit.title', { name: name(submission.organization) })} description={`${submission.period.label} · ${sm(`formType.${submission.form_type}`)} · ${sm('common.revision', { n: submission.revision_no })}`}
            backHref={route('service-monitoring.submissions.index')}
            actions={<div className="flex flex-wrap gap-2">
                {can.open_clarification && <Link className={secondaryBtn} href={route('service-monitoring.clarifications.index', { submission_id: submission.id })}>{sm('edit.askSupport')}</Link>}
                {can.submit && <button type="button" className={primaryBtn} onClick={() => setAttesting(true)}>{sm('edit.submit')}</button>}
            </div>} />}>
            <Head title={sm('edit.title', { name: name(submission.organization) })} />
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-3 text-sm">
                    <SmStatusBadge kind="submission" status={submission.status} />
                    <span>{placeDate(sm('edit.deadline', { date: DATE_MARK }), <LocalizedDateDisplay value={submission.period.submission_due_on} />)}</span>
                    {submission.extension_due_at && <span className="text-amber-800 dark:text-amber-200">{placeDate(sm('edit.extended', { date: DATE_MARK }), <LocalizedDateDisplay value={submission.extension_due_at} withTime />)}</span>}
                </div>
                {submission.status === 'returned' && (
                    <div className="rounded-panel border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                        <p>{sm('edit.returnedBanner')}</p>
                        {returnReason && <p className="mt-1 font-medium">{sm('edit.returnedReason', { reason: returnReason })}</p>}
                    </div>
                )}
                {!editable && <p className={`${panelCls} p-3 text-sm`}>{sm('edit.readOnly')}</p>}
                {serverErrors.length > 0 && (
                    <div role="alert" className="rounded-panel border border-red-300 bg-red-50 p-3 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100">
                        <p className="font-semibold">{sm('common.errorsTitle')}</p>
                        <ul className="list-disc ps-5">{serverErrors.map(([key, message]) => <li key={key}>{message}</li>)}</ul>
                    </div>
                )}

                <div role="tablist" aria-label={sm('edit.sectionsNav')} className="flex flex-wrap gap-1 border-b border-(--app-border)">
                    {SECTIONS.map((n, index) => (
                        <button key={n} ref={(el) => { tabRefs.current[index] = el; }} type="button" role="tab" id={`${id}-tab-${n}`} aria-controls={`${id}-panel-${n}`} aria-selected={tab === n} tabIndex={tab === n ? 0 : -1}
                            onKeyDown={(e) => onTabKey(e, index)} onClick={() => setTab(n)}
                            className={`rounded-t-md px-3 py-2 text-sm ${tab === n ? 'border-b-2 border-(--app-foreground) font-semibold' : 'text-(--app-muted-foreground)'}`}>
                            {n === CUSTOM_SECTION ? sm('templates.customTab') : sm('edit.section', { n })} <span aria-hidden="true">{completeness[n] ? '✓' : '•'}</span>
                            <span className="sr-only">{completeness[n] ? sm('edit.complete') : sm('edit.incomplete')}</span>
                        </button>
                    ))}
                </div>

                {SECTIONS.map((n) => (
                    <section key={n} id={`${id}-panel-${n}`} role="tabpanel" aria-labelledby={`${id}-tab-${n}`} hidden={tab !== n} className="space-y-4">
                        <div className={`${panelCls} flex flex-wrap items-center justify-between gap-3 p-3`}>
                            <div>
                                <h2 className="text-sm font-semibold text-(--app-foreground)">{sectionTitle(n)}</h2>
                                <p className="text-xs text-(--app-muted-foreground)">{completeness[n] ? sm('edit.complete') : sm('edit.incomplete')}</p>
                            </div>
                            {n !== CUSTOM_SECTION && (
                                <label className="flex items-center gap-2 text-sm" title={sm('edit.nothingHint')}>
                                    <input type="checkbox" checked={submission.nothing[n]} disabled={!editable} onChange={(e) => toggleNothing(n, e.target.checked)} />
                                    {sm('edit.nothing')}
                                </label>
                            )}
                        </div>
                        {(problems[n] ?? []).length > 0 && <ul className="list-disc ps-5 text-sm text-amber-800 dark:text-amber-200">{(problems[n] ?? []).map((p, i) => <li key={i}>{p}</li>)}</ul>}
                        {comments.filter((c) => c.section === n).length > 0 && (
                            <div className="rounded-panel border border-sky-300 bg-sky-50 p-3 text-sm dark:border-sky-800 dark:bg-sky-950">
                                <p className="font-semibold">{sm('edit.comments')}</p>
                                <ul className="space-y-1">
                                    {comments.filter((c) => c.section === n).map((c) => (
                                        <li key={c.id}>{c.line_reference && <span className="me-1 text-xs">[{sm('edit.line', { line: c.line_reference })}]</span>}{c.body} <span className="text-xs text-(--app-muted-foreground)">— {c.author}</span></li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {n === 1 && <Section1Panel submissionId={submission.id} level={submission.level} quarter={submission.period} definition={definition} entries={section1.entries} named={section1.named} summary={section1.summary} editable={editable && !submission.nothing[1]} suggestions={suggestions} />}
                        {n === 2 && <Section2Panel submissionId={submission.id} definition={definition} values={section2.values} prefill={section2.prefill} narrative={submission.special_support_narrative} driverListEmpty={section2.driver_list_empty} notices={section2.notices} editable={editable} />}
                        {n === 3 && <Section3Panel submissionId={submission.id} definition={definition} totals={section3.totals} services={section3.services} catalog={section3.catalog} fiscalYear={section3.fiscal_year} editable={editable && !submission.nothing[3]} totalsEditable={editable} />}
                        {n === CUSTOM_SECTION && definition.custom && <CustomSectionsPanel submissionId={submission.id} schema={definition.custom} data={custom.data} editable={editable} errors={errors} />}
                        {n === 4 && <Section4Panel submissionId={submission.id} definition={definition} totals={section3.totals} values={section4.values} minText={section4.min_text} editable={editable && !submission.nothing[4]} />}
                    </section>
                ))}

            </div>

            <Modal show={attesting} onClose={() => setAttesting(false)} maxWidth="lg">
                <div className="space-y-4 p-5" aria-labelledby={`${id}-attest`}>
                    <h2 id={`${id}-attest`} className="text-base font-semibold">{sm('edit.submitTitle')}</h2>
                    {allProblems.length > 0 && (
                        <div role="alert" className="text-sm text-red-800 dark:text-red-200">
                            <p className="font-semibold">{sm('edit.problems')}</p>
                            <ul className="list-disc ps-5">{allProblems.map((p, i) => <li key={i}>{p}</li>)}</ul>
                        </div>
                    )}
                    <section className="space-y-2">
                        <h3 className="text-sm font-semibold">{sm('edit.stampedCopy')}</h3>
                        <p className="text-xs text-(--app-muted-foreground)">{require_stamped_copy ? sm('edit.stampedRequired') : sm('edit.stampedOptional')}</p>
                        <ul className="text-sm">{attachments.map((a) => <li key={a.id}><a className="underline" href={a.download_url}>{a.original_name}</a></li>)}</ul>
                        <form onSubmit={sendFile} className="flex flex-wrap items-center gap-2">
                            <label htmlFor={`${id}-file`} className="sr-only">{sm('edit.stampedCopy')}</label>
                            <input id={`${id}-file`} type="file" accept="application/pdf,image/jpeg,image/png" onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)} />
                            <button type="submit" className={secondaryBtn} disabled={!upload.data.file || upload.processing}>{sm('edit.upload')}</button>
                        </form>
                        {upload.errors.file && <p className={errorCls}>{upload.errors.file}</p>}
                    </section>
                    <form onSubmit={submit} className="space-y-3">
                        <blockquote className="rounded-panel border border-(--app-border) bg-(--app-surface-muted) p-3 text-sm">{declaration}</blockquote>
                        <label className={`${labelCls} flex items-center gap-2 text-sm`}>
                            <input type="checkbox" autoFocus checked={attest.data.declaration} onChange={(e) => attest.setData('declaration', e.target.checked)} />
                            {sm('edit.declarationLabel')}
                        </label>
                        {Object.values(attest.errors).map((m, i) => <p key={i} className={errorCls}>{m}</p>)}
                        <div className="flex justify-end gap-2">
                            <button type="button" className={secondaryBtn} onClick={() => setAttesting(false)}>{sm('common.cancel')}</button>
                            <button type="submit" className={primaryBtn} disabled={!attest.data.declaration || attest.processing}>{sm('edit.submit')}</button>
                        </div>
                    </form>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
