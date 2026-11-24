import ConfirmationDialog from '@/Components/ConfirmationDialog';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import PageHeader from '@/Components/PageHeader';
import { cx } from '@/Components/ui';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Deadline, WorkflowBadge } from '@/modules/reporting/components/Badges';
import { AttachmentList } from '@/modules/reporting/components/Files';
import { ActionErrors, Notice } from '@/modules/reporting/components/Notices';
import { badge, errorCls, inputCls, labelCls, mutedCls, panelCls, primaryBtn, secondaryBtn, sectionTitleCls, serverMessage, textareaCls, useReporting } from '@/modules/reporting/lib/helpers';
import type { AttachmentRow, CommentRow, ObligationRow, TemplateSection } from '@/modules/reporting/types';
import { Head, Link, router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState, type ChangeEvent, type JSX } from 'react';

interface Draft {
    version_no: number;
    mode: 'structured' | 'upload';
    content: Record<string, string>;
    summary: string | null;
    is_nil: boolean;
    nil_reason: string | null;
    completeness_declared: boolean;
    lock_version: number;
    updated_at: string | null;
}

interface Props {
    obligation: ObligationRow;
    type: { instructions_en: string | null; instructions_am: string | null; allow_nil_report: boolean; modes: ('structured' | 'upload')[]; late_policy: string };
    sections: TemplateSection[];
    required_attachments: { key: string; label_en: string; label_am: string }[];
    draft: Draft;
    attachments: AttachmentRow[];
    feedback: CommentRow[];
    can: { submit: boolean };
    max_upload_kb: number;
}

type SaveState = 'saved' | 'dirty' | 'saving' | 'conflict' | 'error';

/**
 * Preparing one report, in either mode, with the same deadline, receipt and
 * review afterwards.
 *
 * The draft saves itself a moment after the writer stops typing, and the
 * state is always on screen: saved, saving, unsaved, or refused. If a
 * colleague saved in the meantime the page says so and keeps the writer's
 * text on screen rather than overwriting either copy. Submitting is a
 * separate, confirmed step; a draft or an uploaded file alone is never a
 * submission.
 */
export default function Prepare({ obligation, type, sections, required_attachments, draft, attachments, feedback, can, max_upload_kb }: Props): JSX.Element {
    const { rt, name, label, pair, moment, am, subject } = useReporting();
    const [form, setForm] = useState({ mode: draft.mode, content: { ...draft.content }, summary: draft.summary ?? '', is_nil: draft.is_nil, nil_reason: draft.nil_reason ?? '', completeness_declared: draft.completeness_declared });
    const [state, setState] = useState<SaveState>('saved');
    const [savedAt, setSavedAt] = useState<string | null>(draft.updated_at);
    const [message, setMessage] = useState<string | null>(null);
    const [files, setFiles] = useState<AttachmentRow[]>(attachments);
    const [upload, setUpload] = useState<{ name: string; percent: number } | null>(null);
    const [uploadError, setUploadError] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const lock = useRef(draft.lock_version);
    const pending = useRef<typeof form | null>(null);
    const saving = useRef(false);
    const late = new Date(obligation.effective_due_at).getTime() < Date.now() && obligation.first_submitted_at === null;

    const save = useCallback(async (): Promise<boolean> => {
        if (saving.current || pending.current === null) return true;
        saving.current = true;
        const payload = pending.current;
        pending.current = null;
        setState('saving');
        try {
            const response = await window.axios.put(route('reporting.prepare.save', obligation.id), { ...payload, lock_version: lock.current });
            lock.current = response.data.lock_version;
            setSavedAt(response.data.saved_at);
            setMessage(null);
            setState(pending.current === null ? 'saved' : 'dirty');
            saving.current = false;
            if (pending.current !== null) return save();

            return true;
        } catch (error) {
            saving.current = false;
            const status = (error as { response?: { status?: number } }).response?.status;
            setState(status === 409 ? 'conflict' : 'error');
            setMessage(serverMessage(error, rt('prepare.saveFailed')));
            // Keep what was typed so the next change, or "save now", sends it again.
            pending.current = pending.current ?? payload;

            return false;
        }
    }, [obligation.id, rt]);

    // Autosave a moment after the last change.
    // A patch may be computed from the latest state, so two sections changing in quick
    // succession (a paste, then a second editor) never overwrite each other with a stale copy.
    const change = (patch: Partial<typeof form> | ((current: typeof form) => Partial<typeof form>)) => {
        setForm((current) => {
            const next = { ...current, ...(typeof patch === 'function' ? patch(current) : patch) };
            pending.current = next;

            return next;
        });
        setState((current) => (current === 'conflict' ? 'conflict' : 'dirty'));
    };
    useEffect(() => {
        if (state !== 'dirty') return;
        const timer = setTimeout(() => void save(), 2500);

        return () => clearTimeout(timer);
    }, [form, state, save]);

    // Leaving with unsaved text: ask first.
    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (pending.current !== null) event.preventDefault();
        };
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, []);

    const pick = async (event: ChangeEvent<HTMLInputElement>, kind: AttachmentRow['kind'], requirementKey?: string) => {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file) return;
        setUploadError(null);
        if (file.size > max_upload_kb * 1024) {
            setUploadError(rt('prepare.tooLarge', { mb: Math.floor(max_upload_kb / 1024) }));

            return;
        }
        const data = new FormData();
        data.append('file', file);
        data.append('kind', kind);
        if (requirementKey) data.append('requirement_key', requirementKey);
        setUpload({ name: file.name, percent: 0 });
        try {
            const response = await window.axios.post(route('reporting.prepare.upload', obligation.id), data, {
                headers: { Accept: 'application/json' },
                onUploadProgress: (progress: { loaded: number; total?: number }) => setUpload({ name: file.name, percent: progress.total ? Math.round((progress.loaded / progress.total) * 100) : 0 }),
            });
            const added: AttachmentRow = response.data.attachment;
            setFiles((current) => [...current.filter((f) => !(kind === 'report_document' && f.kind === 'report_document')), added]);
        } catch (error) {
            setUploadError(serverMessage(error, rt('prepare.uploadFailed')));
        } finally {
            setUpload(null);
        }
    };

    const remove = async (file: AttachmentRow) => {
        try {
            await window.axios.delete(route('reporting.prepare.remove', file.id), { headers: { Accept: 'application/json' } });
            setFiles((current) => current.filter((f) => f.id !== file.id));
        } catch (error) {
            setUploadError(serverMessage(error, rt('prepare.uploadFailed')));
        }
    };

    const submit = async (lateReason: string) => {
        setSubmitting(true);
        // Everything typed is saved first, so what is submitted is what is on screen.
        if (!(await save())) {
            setSubmitting(false);
            setConfirming(false);

            return;
        }
        router.post(route('reporting.prepare.submit', obligation.id), { lock_version: lock.current, late_reason: lateReason || null }, {
            onFinish: () => { setSubmitting(false); setConfirming(false); },
        });
    };

    const reportDocument = files.find((f) => f.kind === 'report_document');
    const missingSections = form.mode === 'structured' && !form.is_nil ? sections.filter((s) => s.is_required && (form.content[s.key] ?? '').replace(/<[^>]*>|&nbsp;/g, '').trim() === '') : [];
    const missingFiles = form.is_nil ? [] : required_attachments.filter((r) => !files.some((f) => f.requirement_key === r.key));
    const ready = form.is_nil ? form.nil_reason.trim() !== ''
        : missingFiles.length === 0 && (form.mode === 'structured' ? missingSections.length === 0 : Boolean(reportDocument) && form.summary.trim() !== '' && form.completeness_declared);
    const instructions = pair(type.instructions_en, type.instructions_am);

    return (
        <AuthenticatedLayout header={<PageHeader title={`${name(obligation.report_type)} · ${label(obligation.period)}`} description={subject(obligation)} backHref={route('reporting.assignments')} />}>
            <Head title={rt('prepare.title')} />
            <ActionErrors />
            <div className="grid gap-5 pb-24 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0 space-y-5">
                    {feedback.length > 0 && obligation.workflow === 'returned' && (
                        <section className={cx(panelCls, 'border-amber-300 p-4 dark:border-amber-700')} aria-labelledby="pr-feedback">
                            <h2 id="pr-feedback" className={sectionTitleCls}>{rt('prepare.feedbackTitle')}</h2>
                            <ul className="mt-2 space-y-2">
                                {feedback.slice(0, 5).map((comment) => (
                                    <li key={comment.id} className="text-sm">
                                        <p className="whitespace-pre-line">{comment.body}</p>
                                        <p className={cx(mutedCls, 'text-xs')}>{comment.author_name} · {moment(comment.created_at)}{comment.section_key ? ` · ${pair(sections.find((s) => s.key === comment.section_key)?.title_en, sections.find((s) => s.key === comment.section_key)?.title_am)}` : ''}</p>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}

                    {instructions && <Notice><span className="whitespace-pre-line">{instructions}</span></Notice>}

                    {/* How the report is submitted */}
                    <section className={cx(panelCls, 'space-y-3 p-4')} aria-labelledby="pr-mode">
                        <h2 id="pr-mode" className={sectionTitleCls}>{rt('prepare.modeTitle')}</h2>
                        {type.modes.length > 1 && (
                            <fieldset className="grid gap-2 sm:grid-cols-2" disabled={form.is_nil}>
                                <legend className="sr-only">{rt('prepare.modeTitle')}</legend>
                                {type.modes.map((mode) => (
                                    <label key={mode} className={cx('flex cursor-pointer gap-3 rounded-control border p-3 text-sm', form.mode === mode ? 'border-primary bg-(--app-muted)' : 'border-(--app-border)')}>
                                        <input type="radio" name="mode" className="mt-1" checked={form.mode === mode} onChange={() => change({ mode })} />
                                        <span><span className="block font-medium">{rt(`prepare.mode.${mode}`)}</span><span className={cx(mutedCls, 'text-xs')}>{rt(`prepare.mode.${mode}Help`)}</span></span>
                                    </label>
                                ))}
                            </fieldset>
                        )}
                        {type.allow_nil_report && (
                            <label className="flex items-start gap-3 text-sm">
                                <input type="checkbox" className="mt-1" checked={form.is_nil} onChange={(e) => change({ is_nil: e.target.checked })} />
                                <span><span className="font-medium">{rt('prepare.nil')}</span><span className={cx(mutedCls, 'block text-xs')}>{rt('prepare.nilHelp')}</span></span>
                            </label>
                        )}
                        {form.is_nil && (
                            <div>
                                <label htmlFor="pr-nil" className={labelCls}>{rt('prepare.nilReason')}</label>
                                <textarea id="pr-nil" className={textareaCls} rows={3} maxLength={2000} required value={form.nil_reason} onChange={(e) => change({ nil_reason: e.target.value })} />
                            </div>
                        )}
                    </section>

                    {/* Structured narrative */}
                    {!form.is_nil && form.mode === 'structured' && sections.map((section, index) => (
                        <section key={section.key} className={cx(panelCls, 'p-4')} aria-labelledby={`pr-s-${section.key}`}>
                            <RichTextEditor
                                id={`pr-s-${section.key}`}
                                label={<span className={sectionTitleCls}>{index + 1}. {am ? section.title_am : section.title_en}</span>}
                                helperText={pair(section.guidance_en, section.guidance_am) || undefined}
                                required={section.is_required}
                                preset="document"
                                value={form.content[section.key] ?? ''}
                                onChange={(html) => change((current) => ({ content: { ...current.content, [section.key]: html } }))}
                            />
                        </section>
                    ))}

                    {/* Word upload */}
                    {!form.is_nil && form.mode === 'upload' && (
                        <section className={cx(panelCls, 'space-y-4 p-4')} aria-labelledby="pr-upload">
                            <h2 id="pr-upload" className={sectionTitleCls}>{rt('prepare.uploadTitle')}</h2>
                            <ol className="list-decimal space-y-3 ps-5 text-sm">
                                <li>
                                    {rt('prepare.uploadStep1')}{' '}
                                    <a href={route('reporting.prepare.template', obligation.id)} className={cx(secondaryBtn, 'ms-1')}>{rt('prepare.downloadTemplate')}</a>
                                </li>
                                <li>{rt('prepare.uploadStep2')}</li>
                                <li>
                                    {rt('prepare.uploadStep3')}
                                    <div className="mt-2">
                                        {reportDocument && <AttachmentList files={[reportDocument]} onRemove={remove} />}
                                        <label className={cx(secondaryBtn, 'mt-2 cursor-pointer')}>
                                            {rt(reportDocument ? 'prepare.replaceFile' : 'prepare.chooseFile')}
                                            <input type="file" className="sr-only" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" onChange={(e) => void pick(e, 'report_document')} />
                                        </label>
                                        <p className={cx(mutedCls, 'mt-1 text-xs')}>{rt('prepare.docxOnly', { mb: Math.floor(max_upload_kb / 1024) })}</p>
                                    </div>
                                </li>
                            </ol>
                            <div>
                                <label htmlFor="pr-summary" className={labelCls}>{rt('prepare.summary')}</label>
                                <textarea id="pr-summary" className={textareaCls} rows={5} maxLength={5000} required value={form.summary} onChange={(e) => change({ summary: e.target.value })} aria-describedby="pr-summary-help" />
                                <p id="pr-summary-help" className={cx(mutedCls, 'mt-1 text-xs')}>{rt('prepare.summaryHelp')}</p>
                            </div>
                            <fieldset>
                                <legend className={labelCls}>{rt('prepare.sectionsToCover')}</legend>
                                <ul className="list-disc ps-5 text-sm">{sections.filter((s) => s.is_required).map((s) => <li key={s.key}>{am ? s.title_am : s.title_en}</li>)}</ul>
                                <label className="mt-3 flex items-start gap-3 text-sm">
                                    <input type="checkbox" className="mt-1" checked={form.completeness_declared} onChange={(e) => change({ completeness_declared: e.target.checked })} />
                                    <span>{rt('prepare.declaration')}<span className={cx(mutedCls, 'block text-xs')}>{rt('prepare.declarationHelp')}</span></span>
                                </label>
                            </fieldset>
                        </section>
                    )}

                    {/* Annexes and required attachments */}
                    {!form.is_nil && (
                        <section className={cx(panelCls, 'space-y-3 p-4')} aria-labelledby="pr-files">
                            <h2 id="pr-files" className={sectionTitleCls}>{rt('prepare.filesTitle')}</h2>
                            {required_attachments.map((requirement) => {
                                const present = files.filter((f) => f.requirement_key === requirement.key);

                                return (
                                    <div key={requirement.key} className="rounded-control border border-(--app-border) p-3">
                                        <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                            {pair(requirement.label_en, requirement.label_am)}
                                            <span className={badge(present.length > 0 ? 'success' : 'warning')}>{rt(present.length > 0 ? 'prepare.attached' : 'prepare.required')}</span>
                                        </p>
                                        {present.length > 0 && <AttachmentList files={present} onRemove={remove} />}
                                        <label className={cx(secondaryBtn, 'mt-2 cursor-pointer')}>
                                            {rt('prepare.addFile')}
                                            <input type="file" className="sr-only" onChange={(e) => void pick(e, 'annex', requirement.key)} />
                                        </label>
                                    </div>
                                );
                            })}
                            <AttachmentList files={files.filter((f) => f.kind !== 'report_document' && !f.requirement_key)} onRemove={remove} empty={rt('prepare.noAnnexes')} />
                            <label className={cx(secondaryBtn, 'cursor-pointer')}>
                                {rt('prepare.addAnnex')}
                                <input type="file" className="sr-only" onChange={(e) => void pick(e, 'annex')} />
                            </label>
                            <p className={cx(mutedCls, 'text-xs')}>{rt('prepare.annexTypes', { mb: Math.floor(max_upload_kb / 1024) })}</p>
                        </section>
                    )}

                    {upload && (
                        <div className={cx(panelCls, 'p-3')} role="status" aria-live="polite">
                            <p className="text-sm">{rt('prepare.uploading', { name: upload.name })} <span className="tabular-nums">{upload.percent}%</span></p>
                            <progress className="mt-1 h-2 w-full" max={100} value={upload.percent} aria-label={rt('prepare.uploading', { name: upload.name })} />
                        </div>
                    )}
                    {uploadError && <p className={errorCls} role="alert">{uploadError}</p>}
                </div>

                {/* Deadline, save state and submission: always in view */}
                <aside className="lg:sticky lg:top-4 lg:self-start">
                    <div className={cx(panelCls, 'space-y-4 p-4')}>
                        <div>
                            <p className={labelCls}>{rt(obligation.workflow === 'returned' ? 'assignments.revisionDue' : 'common.deadline')}</p>
                            {obligation.workflow === 'returned' && obligation.revision_due_at ? <p className="text-sm">{moment(obligation.revision_due_at)}</p> : <Deadline row={obligation} />}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <WorkflowBadge status={obligation.workflow} />
                            <span className={cx(mutedCls, 'text-xs')}>{rt('common.version', { n: draft.version_no })}</span>
                        </div>
                        <div aria-live="polite" role="status">
                            <p className={cx('text-sm font-medium', state === 'conflict' || state === 'error' ? 'text-red-700 dark:text-red-400' : state === 'saved' ? 'text-green-700 dark:text-green-400' : 'text-(--app-foreground)')}>{rt(`prepare.state.${state}`)}</p>
                            {state === 'saved' && savedAt && <p className={cx(mutedCls, 'text-xs')}>{moment(savedAt)}</p>}
                            {message && <p className={cx(errorCls, 'mt-1')}>{message}</p>}
                            {state === 'conflict' && <button type="button" className={cx(secondaryBtn, 'mt-2')} onClick={() => router.reload()}>{rt('prepare.reload')}</button>}
                            {(state === 'dirty' || state === 'error') && <button type="button" className={cx(secondaryBtn, 'mt-2')} onClick={() => void save()}>{rt('prepare.saveNow')}</button>}
                        </div>
                        <div className="space-y-2 border-t border-(--app-border) pt-3">
                            <a href={route('reporting.prepare.draft-document', obligation.id)} className={cx(secondaryBtn, 'w-full justify-center')}>{rt('prepare.downloadDraft')}</a>
                            <Link href={route('reporting.obligations.show', obligation.id)} className={cx(secondaryBtn, 'w-full justify-center')}>{rt('prepare.reportPage')}</Link>
                        </div>
                        <div className="border-t border-(--app-border) pt-3">
                            {!ready && (
                                <ul className="mb-2 list-disc space-y-1 ps-5 text-xs text-(--app-muted-foreground)" aria-label={rt('prepare.beforeSubmit')}>
                                    {form.is_nil && form.nil_reason.trim() === '' && <li>{rt('prepare.need.nilReason')}</li>}
                                    {missingSections.map((s) => <li key={s.key}>{rt('prepare.need.section', { title: am ? s.title_am : s.title_en })}</li>)}
                                    {!form.is_nil && form.mode === 'upload' && !reportDocument && <li>{rt('prepare.need.document')}</li>}
                                    {!form.is_nil && form.mode === 'upload' && form.summary.trim() === '' && <li>{rt('prepare.need.summary')}</li>}
                                    {!form.is_nil && form.mode === 'upload' && !form.completeness_declared && <li>{rt('prepare.need.declaration')}</li>}
                                    {missingFiles.map((r) => <li key={r.key}>{rt('prepare.need.attachment', { title: pair(r.label_en, r.label_am) })}</li>)}
                                </ul>
                            )}
                            {can.submit
                                ? <button type="button" className={cx(primaryBtn, 'w-full justify-center')} disabled={!ready || state === 'conflict' || submitting} onClick={() => setConfirming(true)}>{rt('prepare.submit')}</button>
                                : <p className={cx(mutedCls, 'text-xs')}>{rt('prepare.cannotSubmit')}</p>}
                            {late && type.late_policy === 'block' && <p className={cx(errorCls, 'mt-2')}>{rt('prepare.lateBlocked')}</p>}
                        </div>
                    </div>
                </aside>
            </div>

            <ConfirmationDialog
                open={confirming} onClose={() => setConfirming(false)} loading={submitting}
                title={rt('prepare.confirmTitle')} description={rt(late ? 'prepare.confirmLate' : 'prepare.confirmDescription')}
                confirmLabel={rt('prepare.submit')}
                requireReason={late && type.late_policy === 'accept_with_reason'} reasonLabel={rt('prepare.lateReason')}
                onConfirm={(reason) => void submit(reason ?? '')}
            />
        </AuthenticatedLayout>
    );
}
