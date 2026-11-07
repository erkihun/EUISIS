import { useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type DragEvent, type ReactNode } from 'react';
import RichTextEditor from '@/Components/forms/RichTextEditor';
import RichTextViewer from '@/Components/forms/RichTextViewer';
import DateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import DatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { AlertTriangle, CheckCircle, ClockIcon, InfoIcon, TrashIcon, Upload, XCircle } from '@/Components/Icons';
import { Workspace } from '@/Components/grievances/Workspace';
import { Details, FieldError, FormRow, Section, fmt, inputCls, nameOf, primaryBtn, secondaryBtn, textareaCls, useEnumLabel } from '@/Components/grievances/ui';
import { useLocale } from '@/hooks/useLocale';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { toast } from '@/lib/toast';
import { cx } from '@/Components/ui';
import type { FilingDeadline, PortalFormProps } from '@/types/grievances';

/*
 * The grievance application (Regulation 201/2018 Art. 32–33) in four steps.
 * One form state the whole way: a draft can be saved from any step, and the
 * checklist beside the form says what sending still needs. The filing
 * deadline is asked of the server, which counts it on the calendar the
 * submission is judged by.
 */
const STEPS = ['matter', 'before', 'remedy', 'review'] as const;
type Step = typeof STEPS[number];

/** Which step a server error belongs to, so a refused save opens that step. */
const STEP_OF_FIELD: Record<string, Step> = {
    subject: 'matter', category_id: 'matter', incident_date: 'matter', respondent_description: 'matter', description: 'matter',
    immediate_supervisor_name: 'before', written_issue_raised_at: 'before', late_submission_reason: 'before', cause_ended_at: 'before',
    requested_remedy: 'remedy', applicant_address: 'remedy', files: 'remedy', file_types: 'remedy',
    declaration: 'review', amendment_reason: 'review', applicant_declared_at: 'review',
};

const MAX_FILES = 10;

function stripHtml(html: string): string {
    return html.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').trim();
}

export default function Form({ grievance: g, categories, evidenceTypes, allowedExtensions, maxFileKb, writtenIssueRequired, filing }: PortalFormProps) {
    const { t, locale } = useLocale();
    const label = useEnumLabel();
    const calendar = useCalendarSystem();
    /** A date as the reader's calendar shows it, for use inside a sentence. */
    const dateText = (iso: string | null | undefined) => (iso ? formatDateDisplay(iso, calendar, locale) || iso : '');
    const formRef = useRef<HTMLFormElement>(null);
    const [step, setStep] = useState<Step>('matter');
    const [fileError, setFileError] = useState<string | null>(null);
    const [dragging, setDragging] = useState(false);
    const [deadline, setDeadline] = useState<FilingDeadline | null>(null);
    const [checkingDeadline, setCheckingDeadline] = useState(false);

    const form = useForm({
        subject: g?.subject ?? '', description: g?.description ?? '', category_id: g?.category_id ?? '', incident_date: g?.incident_date ?? '',
        respondent_description: g?.respondent_description ?? '', written_issue_raised_at: g?.written_issue_raised_at ?? '',
        immediate_supervisor_name: g?.immediate_supervisor_name ?? '', applicant_address: g?.applicant_address ?? '', requested_remedy: g?.requested_remedy ?? '',
        late_submission_reason: g?.late_submission_reason ?? '', cause_ended_at: g?.cause_ended_at ?? '',
        declaration: false, amendment_reason: '', files: [] as File[], file_types: [] as string[], submit: false,
    });
    const d = form.data;
    const amending = !!g && g.status !== 'draft';
    const attached = g?.attached_files ?? [];
    const stepIndex = STEPS.indexOf(step);
    const maxSize = maxFileKb >= 1024 ? `${maxFileKb / 1024} MB` : `${maxFileKb} KB`;

    // ── The filing window, from the server's calendar ────────────────────────
    useEffect(() => {
        if (!d.written_issue_raised_at) { setDeadline(null); return; }
        setCheckingDeadline(true);
        const timer = window.setTimeout(() => {
            window.axios.get<FilingDeadline>(route('employee.grievances.filing-deadline'), {
                params: { raised_at: d.written_issue_raised_at, cause_ended_at: d.cause_ended_at || undefined },
            })
                .then((response) => setDeadline(response.data))
                .catch(() => setDeadline(null))
                .finally(() => setCheckingDeadline(false));
        }, 300);
        return () => window.clearTimeout(timer);
    }, [d.written_issue_raised_at, d.cause_ended_at]);

    const late = deadline?.late ?? false;

    // ── What Art. 32(2) asks for ─────────────────────────────────────────────
    const checklist = useMemo(() => [
        { key: 'subject', step: 'matter' as Step, done: d.subject.trim() !== '' },
        { key: 'category', step: 'matter' as Step, done: d.category_id !== '' },
        { key: 'description', step: 'matter' as Step, done: stripHtml(d.description) !== '' },
        { key: 'incident_date', step: 'matter' as Step, done: d.incident_date !== '' },
        { key: 'supervisor', step: 'before' as Step, done: d.immediate_supervisor_name.trim() !== '' && (!writtenIssueRequired || d.written_issue_raised_at !== '') },
        { key: 'remedy', step: 'remedy' as Step, done: d.requested_remedy.trim() !== '' },
        { key: 'address', step: 'remedy' as Step, done: d.applicant_address.trim() !== '' },
        { key: 'declaration', step: 'review' as Step, done: d.declaration },
    ], [d, writtenIssueRequired]);
    const missing = checklist.filter((item) => !item.done).length;
    const stepDone = (key: Step) => checklist.filter((item) => item.step === key).every((item) => item.done);

    // ── Files ────────────────────────────────────────────────────────────────
    function addFiles(picked: File[]) {
        // Checked here first so a wrong file is named before the upload; the server checks again.
        const rejected = picked.filter((file) => !allowedExtensions.includes(file.name.split('.').pop()?.toLowerCase() ?? '') || file.size > maxFileKb * 1024);
        const accepted = picked.filter((file) => !rejected.includes(file) && !d.files.some((have) => have.name === file.name && have.size === file.size));
        const room = Math.max(0, MAX_FILES - d.files.length);
        const added = accepted.slice(0, room);
        setFileError(rejected.length
            ? `${t('grievancePortal.file_rejected')}: ${rejected.map((file) => file.name).join(', ')}`
            : accepted.length > room ? fmt(t('grievancePortal.files_max'), { max: MAX_FILES }) : null);
        if (added.length) form.setData((data) => ({ ...data, files: [...data.files, ...added], file_types: [...data.file_types, ...added.map(() => 'document')] }));
    }

    function removeFile(index: number) {
        form.setData((data) => ({ ...data, files: data.files.filter((_, i) => i !== index), file_types: data.file_types.filter((_, i) => i !== index) }));
    }

    function onDrop(event: DragEvent<HTMLDivElement>) {
        event.preventDefault();
        setDragging(false);
        addFiles(Array.from(event.dataTransfer.files));
    }

    // ── Moving between steps and saving ──────────────────────────────────────
    function goTo(next: Step) {
        setStep(next);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function continueToNext() {
        // The current step's own required controls, then the editor (not a native control).
        if (!formRef.current?.reportValidity()) return;
        if (step === 'matter' && stripHtml(d.description) === '') {
            toast.error(t('grievances.common.description'));
            return;
        }
        goTo(STEPS[Math.min(stepIndex + 1, STEPS.length - 1)]);
    }

    function save(submit: boolean) {
        if (form.processing) return;
        form.transform(({ amendment_reason, ...data }) => ({ ...data, ...(amending ? { amendment_reason } : {}), submit }));
        form.post(g ? route('employee.grievances.update', g.id) : route('employee.grievances.store'), {
            onError: (errors) => {
                const [firstKey] = Object.keys(errors);
                if (!firstKey) return;
                toast.error(errors[firstKey]);
                goTo(STEP_OF_FIELD[firstKey.split('.')[0]] ?? 'review');
            },
        });
    }

    // Per-file server errors arrive as files.0, files.1 …
    const fileErrors = Object.entries(form.errors).filter(([key]) => key.startsWith('files.')).map(([, message]) => message).join(' ') || undefined;
    const category = categories.find((option) => option.id === d.category_id) ?? null;
    const categoryHelp = category ? ((locale === 'am' && category.description_am) || category.description_en) : null;

    const steps = (
        <ol className="grid gap-2 sm:grid-cols-4" aria-label={t('grievancePortal.checklist')}>
            {STEPS.map((key, index) => {
                const current = key === step;
                const done = key !== 'review' && stepDone(key);
                return (
                    <li key={key}>
                        <button type="button" onClick={() => goTo(key)} aria-current={current ? 'step' : undefined}
                            className={cx('flex w-full items-center gap-3 rounded-lg border px-3 py-2 text-left transition-colors',
                                current ? 'border-primary bg-primary/5' : 'border-(--app-border) hover:bg-(--app-surface-muted)')}>
                            <span className={cx('flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                                current ? 'bg-primary text-white' : done ? 'bg-emerald-600 text-white' : 'bg-(--app-surface-muted) text-(--app-muted-foreground)')}>
                                {done && !current ? <CheckCircle className="h-4 w-4" aria-hidden="true" /> : index + 1}
                            </span>
                            <span className="min-w-0">
                                <span className="block text-[11px] uppercase tracking-wide text-(--app-muted-foreground)">{fmt(t('grievancePortal.step_of'), { n: index + 1, total: STEPS.length })}</span>
                                <span className={cx('block truncate text-sm font-medium', current ? 'text-primary' : 'text-(--app-foreground)')}>{t(`grievancePortal.steps.${key}`)}</span>
                            </span>
                        </button>
                    </li>
                );
            })}
        </ol>
    );

    const deadlinePanel = (
        <div className="rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-4 text-sm">
            <p className="flex items-center gap-2 font-semibold text-(--app-foreground)"><ClockIcon className="h-4 w-4" aria-hidden="true" />{t('grievancePortal.deadline_title')}</p>
            <p className="mt-1 text-xs text-(--app-muted-foreground)">{fmt(t('grievancePortal.deadline_help'), { article: filing.article })}</p>
            <div className="mt-3" aria-live="polite">
                {!d.written_issue_raised_at ? (
                    <p className="text-(--app-muted-foreground)">{t('grievancePortal.deadline_enter_date')}</p>
                ) : checkingDeadline && !deadline ? (
                    <p className="text-(--app-muted-foreground)">{t('grievancePortal.deadline_checking')}</p>
                ) : deadline && !deadline.late ? (
                    <p className="flex items-start gap-2 text-emerald-800 dark:text-emerald-300">
                        <CheckCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                        <span>
                            <span className="font-medium">{fmt(t('grievancePortal.deadline_send_by'), { date: dateText(deadline.due_at) })}</span>
                            <span className="block text-xs">{deadline.working_days_left > 1 ? fmt(t('grievancePortal.deadline_days_left'), { n: deadline.working_days_left }) : deadline.working_days_left === 1 ? t('grievancePortal.deadline_day_left_one') : t('grievancePortal.deadline_last_day')}</span>
                        </span>
                    </p>
                ) : deadline ? (
                    <div className="space-y-2">
                        <p className="flex items-start gap-2 text-red-700 dark:text-red-300">
                            <XCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>{fmt(t('grievancePortal.deadline_passed'), { n: filing.working_days, date: dateText(deadline.due_at), late_n: filing.late_working_days, late_article: filing.late_article })}</span>
                        </p>
                        {deadline.force_majeure_due_at && (
                            <p className={cx('flex items-start gap-2', deadline.force_majeure_open ? 'text-amber-800 dark:text-amber-300' : 'text-red-700 dark:text-red-300')}>
                                {deadline.force_majeure_open ? <ClockIcon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /> : <XCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />}
                                <span>{fmt(t(deadline.force_majeure_open ? 'grievancePortal.deadline_late_open' : 'grievancePortal.deadline_late_closed'), { date: dateText(deadline.force_majeure_due_at) })}</span>
                            </p>
                        )}
                    </div>
                ) : null}
            </div>
        </div>
    );

    const checklistPanel = (
        <Section title={t('grievancePortal.checklist')} description={t('grievancePortal.checklist_help')}>
            <p className={cx('mb-3 flex items-center gap-2 text-sm font-medium', missing === 0 ? 'text-emerald-700 dark:text-emerald-300' : 'text-(--app-foreground)')}>
                {missing === 0 ? <CheckCircle className="h-4 w-4" aria-hidden="true" /> : <InfoIcon className="h-4 w-4" aria-hidden="true" />}
                {missing === 0 ? t('grievancePortal.ready') : missing === 1 ? t('grievancePortal.item_missing_one') : fmt(t('grievancePortal.items_missing'), { n: missing })}
            </p>
            <ul className="space-y-1.5">
                {checklist.map((item) => (
                    <li key={item.key}>
                        <button type="button" onClick={() => goTo(item.step)} className="flex w-full items-center gap-2 rounded-md px-1 py-0.5 text-left text-sm hover:bg-(--app-surface-muted)">
                            {item.done
                                ? <CheckCircle className="h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" />
                                : <span className="h-4 w-4 shrink-0 rounded-full border-2 border-(--app-border-strong)" aria-hidden="true" />}
                            <span className={item.done ? 'text-(--app-foreground)' : 'text-(--app-muted-foreground)'}>{t(`grievancePortal.check.${item.key}`)}</span>
                        </button>
                    </li>
                ))}
            </ul>
            {d.written_issue_raised_at && deadline && (
                <p className={cx('mt-4 flex items-start gap-2 border-t border-(--app-border) pt-3 text-xs', deadline.late ? 'text-red-700 dark:text-red-300' : 'text-(--app-muted-foreground)')}>
                    {deadline.late ? <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" /> : <ClockIcon className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />}
                    <span>{fmt(t('grievancePortal.deadline_send_by'), { date: dateText(deadline.due_at) })}</span>
                </p>
            )}
        </Section>
    );

    const nextStepsPanel = (
        <Section title={t('grievancePortal.next_steps')}>
            <ol className="space-y-2 text-sm text-(--app-muted-foreground)">
                {[1, 2, 3].map((n) => (
                    <li key={n} className="flex gap-2">
                        <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">{n}</span>
                        <span>{t(`grievancePortal.next_${n}`)}</span>
                    </li>
                ))}
            </ol>
        </Section>
    );

    const reviewRow = (key: Step, items: [string, ReactNode][]) => (
        <Section title={t(`grievancePortal.steps.${key}`)} actions={<button type="button" className={secondaryBtn} onClick={() => goTo(key)}>{t('grievancePortal.edit_step')}</button>}>
            <Details columns={2} items={items.map(([name, value]) => [name, value === '' || value === null || value === undefined ? <span className="text-(--app-muted-foreground)">{t('grievancePortal.not_provided')}</span> : value])} />
        </Section>
    );

    return (
        <Workspace portal title={t(g ? 'grievancePortal.edit' : 'grievancePortal.create')} description={t(g ? 'grievancePortal.edit_description' : 'grievancePortal.create_description')} backHref={route('employee.grievances.index')}>
            {g?.intake_notes && <div role="status" className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-200">{g.intake_notes}</div>}
            {steps}

            <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
                <form ref={formRef} className="min-w-0 space-y-4" onSubmit={(event) => { event.preventDefault(); continueToNext(); }}>
                    {/* 1 — The matter */}
                    {step === 'matter' && (
                        <Section title={t('grievancePortal.steps.matter')} description={t('grievancePortal.step_help.matter')}>
                            <div className="space-y-4">
                                <FormRow label={t('grievances.common.subject')} error={form.errors.subject} help={fmt(t('grievancePortal.characters_left'), { n: 255 - d.subject.length })}>
                                    <input className={inputCls} required maxLength={255} value={d.subject} onChange={(event) => form.setData('subject', event.target.value)} />
                                </FormRow>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormRow label={t('grievances.common.category')} error={form.errors.category_id} help={categoryHelp ?? t('grievancePortal.category_pick_help')}>
                                        <select className={inputCls} required value={d.category_id} onChange={(event) => form.setData('category_id', event.target.value)}>
                                            <option value="">{t('grievances.common.select')}</option>
                                            {categories.map((option) => <option key={option.id} value={option.id}>{nameOf(option, locale)}</option>)}
                                        </select>
                                    </FormRow>
                                    <FormRow label={t('grievances.common.incident_date')} error={form.errors.incident_date}>
                                        <DatePicker value={d.incident_date} onChange={(value) => form.setData('incident_date', value)} />
                                    </FormRow>
                                </div>
                                <FormRow label={t('grievancePortal.respondent')} error={form.errors.respondent_description}>
                                    <textarea className={textareaCls} rows={2} maxLength={2000} value={d.respondent_description} onChange={(event) => form.setData('respondent_description', event.target.value)} />
                                </FormRow>
                                <RichTextEditor id="grievance-description" label={t('grievances.common.description')} helperText={t('grievancePortal.description_help')} required preset="basic" minHeight={240} maxLength={20000} showCount
                                    value={d.description} onChange={(html) => form.setData('description', html)} error={form.errors.description} />
                            </div>
                        </Section>
                    )}

                    {/* 2 — Before the committee */}
                    {step === 'before' && (
                        <Section title={t('grievancePortal.steps.before')} description={t('grievancePortal.step_help.before')}>
                            <div className="space-y-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <FormRow label={t('grievancePortal.immediate_supervisor_name')} error={form.errors.immediate_supervisor_name}>
                                        <input className={inputCls} maxLength={255} value={d.immediate_supervisor_name} onChange={(event) => form.setData('immediate_supervisor_name', event.target.value)} />
                                    </FormRow>
                                    <FormRow label={t('grievancePortal.written_issue_raised_at')} error={form.errors.written_issue_raised_at}>
                                        <DatePicker value={d.written_issue_raised_at} onChange={(value) => form.setData('written_issue_raised_at', value)} />
                                    </FormRow>
                                </div>
                                {deadlinePanel}
                                <details className="rounded-lg border border-(--app-border) p-4 text-sm" open={late || !!(d.late_submission_reason || d.cause_ended_at)}>
                                    <summary className="cursor-pointer font-medium text-(--app-foreground)">{t('grievancePortal.late_filing')}</summary>
                                    <p className="mt-2 text-(--app-muted-foreground)">{t('grievancePortal.late_filing_help')}</p>
                                    {late && (
                                        <p className="mt-2 flex items-start gap-2 text-amber-800 dark:text-amber-300" role="status">
                                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                                            <span>{t('grievancePortal.late_filing_required')}</span>
                                        </p>
                                    )}
                                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                                        <FormRow label={t('grievancePortal.late_submission_reason')} error={form.errors.late_submission_reason}>
                                            <textarea className={textareaCls} rows={3} maxLength={2000} required={late} value={d.late_submission_reason} onChange={(event) => form.setData('late_submission_reason', event.target.value)} />
                                        </FormRow>
                                        <FormRow label={t('grievancePortal.cause_ended_at')} error={form.errors.cause_ended_at}>
                                            <DatePicker value={d.cause_ended_at} required={late} onChange={(value) => form.setData('cause_ended_at', value)} />
                                        </FormRow>
                                    </div>
                                </details>
                            </div>
                        </Section>
                    )}

                    {/* 3 — Remedy, contact and documents */}
                    {step === 'remedy' && (
                        <Section title={t('grievancePortal.steps.remedy')} description={t('grievancePortal.step_help.remedy')}>
                            <div className="space-y-4">
                                <FormRow label={t('grievancePortal.requested_remedy')} help={`${t('grievancePortal.requested_remedy_help')} ${fmt(t('grievancePortal.characters_left'), { n: 5000 - d.requested_remedy.length })}`} error={form.errors.requested_remedy}>
                                    <textarea className={textareaCls} rows={4} maxLength={5000} value={d.requested_remedy} onChange={(event) => form.setData('requested_remedy', event.target.value)} />
                                </FormRow>
                                <FormRow label={t('grievancePortal.applicant_address')} help={t('grievancePortal.applicant_address_help')} error={form.errors.applicant_address}>
                                    <input className={inputCls} maxLength={500} value={d.applicant_address} onChange={(event) => form.setData('applicant_address', event.target.value)} />
                                </FormRow>

                                {attached.length > 0 && (
                                    <div className="rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-3">
                                        <p className="text-sm font-medium text-(--app-foreground)">{t('grievancePortal.already_attached')}</p>
                                        <p className="mt-0.5 text-xs text-(--app-muted-foreground)">{t('grievancePortal.already_attached_help')}</p>
                                        <ul className="mt-2 space-y-1 text-sm text-(--app-foreground)">
                                            {attached.map((file) => <li key={file.id} className="flex items-center gap-2"><CheckCircle className="h-4 w-4 shrink-0 text-emerald-600" aria-hidden="true" /><span className="truncate">{file.title}</span><span className="text-xs text-(--app-muted-foreground)">· {label('evidence_type', file.evidence_type)}</span></li>)}
                                        </ul>
                                    </div>
                                )}
                                <div>
                                    <p className="mb-1 block text-sm font-medium text-(--app-foreground)">{t('grievances.common.files')}</p>
                                    <p className="mb-2 text-xs text-(--app-muted-foreground)">{t('grievancePortal.files_help')} {allowedExtensions.map((ext) => ext.toUpperCase()).join(', ')} · {maxSize} · {fmt(t('grievancePortal.files_max'), { max: MAX_FILES })}</p>
                                    <div onDragOver={(event) => { event.preventDefault(); setDragging(true); }} onDragLeave={() => setDragging(false)} onDrop={onDrop}
                                        className={cx('flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed px-4 py-6 text-center text-sm transition-colors',
                                            dragging ? 'border-primary bg-primary/5' : 'border-(--app-border-strong) bg-(--app-surface-muted)')}>
                                        <Upload className="h-6 w-6 text-(--app-muted-foreground)" aria-hidden="true" />
                                        <p className="text-(--app-muted-foreground)">
                                            {t('grievancePortal.files_drop')}{' '}
                                            <label className="cursor-pointer font-medium text-primary underline-offset-4 hover:underline">
                                                {t('grievancePortal.files_browse')}
                                                <input type="file" multiple className="sr-only" accept={[...allowedExtensions.map((ext) => `.${ext}`), 'application/pdf'].join(',')}
                                                    onChange={(event) => { addFiles(Array.from(event.target.files ?? [])); event.target.value = ''; }} />
                                            </label>
                                        </p>
                                        <p className="text-xs text-(--app-muted-foreground)">{fmt(t('grievancePortal.files_count'), { n: d.files.length, max: MAX_FILES })}</p>
                                    </div>
                                    <FieldError message={fileError ?? form.errors.files ?? fileErrors} />
                                    {d.files.length > 0 && (
                                        <ul className="mt-3 divide-y divide-(--app-border) rounded-lg border border-(--app-border)">
                                            {d.files.map((file, index) => (
                                                <li key={`${file.name}-${file.size}`} className="flex flex-wrap items-center gap-3 px-3 py-2">
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate text-sm text-(--app-foreground)">{file.name}</span>
                                                        <span className="block text-xs text-(--app-muted-foreground)">{(file.size / 1024).toFixed(0)} KB</span>
                                                    </span>
                                                    <label className="flex items-center gap-2 text-xs text-(--app-muted-foreground)">
                                                        {t('grievancePortal.file_type')}
                                                        <select className={cx(inputCls, 'w-auto')} value={d.file_types[index]} onChange={(event) => form.setData('file_types', d.file_types.map((value, n) => n === index ? event.target.value : value))}>
                                                            {evidenceTypes.map((value) => <option key={value} value={value}>{label('evidence_type', value)}</option>)}
                                                        </select>
                                                    </label>
                                                    <button type="button" onClick={() => removeFile(index)} className="inline-flex items-center gap-1 text-xs font-medium text-red-700 hover:underline dark:text-red-400" aria-label={`${t('grievancePortal.remove_file')} ${file.name}`}>
                                                        <TrashIcon className="h-3.5 w-3.5" aria-hidden="true" />{t('grievancePortal.remove_file')}
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </div>
                        </Section>
                    )}

                    {/* 4 — Check and send */}
                    {step === 'review' && (
                        <div className="space-y-4">
                            <p className="text-sm text-(--app-muted-foreground)">{t('grievancePortal.step_help.review')}</p>
                            {reviewRow('matter', [
                                [t('grievances.common.subject'), d.subject],
                                [t('grievances.common.category'), category ? nameOf(category, locale) : ''],
                                [t('grievances.common.incident_date'), d.incident_date ? <DateDisplay value={d.incident_date} /> : ''],
                                [t('grievancePortal.respondent'), d.respondent_description],
                            ])}
                            <Section title={t('grievances.common.description')} actions={<button type="button" className={secondaryBtn} onClick={() => goTo('matter')}>{t('grievancePortal.edit_step')}</button>}>
                                {stripHtml(d.description) === ''
                                    ? <p className="text-sm text-(--app-muted-foreground)">{t('grievancePortal.not_provided')}</p>
                                    : <RichTextViewer html={d.description} className="prose prose-sm max-w-none text-(--app-foreground) dark:prose-invert" />}
                            </Section>
                            {reviewRow('before', [
                                [t('grievancePortal.immediate_supervisor_name'), d.immediate_supervisor_name],
                                [t('grievancePortal.written_issue_raised_at'), d.written_issue_raised_at ? <DateDisplay value={d.written_issue_raised_at} /> : ''],
                                ...(late || d.late_submission_reason || d.cause_ended_at ? [
                                    [t('grievancePortal.late_submission_reason'), d.late_submission_reason] as [string, ReactNode],
                                    [t('grievancePortal.cause_ended_at'), d.cause_ended_at ? <DateDisplay value={d.cause_ended_at} /> : ''] as [string, ReactNode],
                                ] : []),
                            ])}
                            {reviewRow('remedy', [
                                [t('grievancePortal.requested_remedy'), d.requested_remedy],
                                [t('grievancePortal.applicant_address'), d.applicant_address],
                                [t('grievances.common.files'), attached.length + d.files.length > 0
                                    ? <ul className="list-disc pl-4">
                                        {attached.map((file) => <li key={file.id}>{file.title} · {label('evidence_type', file.evidence_type)}</li>)}
                                        {d.files.map((file, index) => <li key={`${file.name}-${index}`}>{file.name} · {label('evidence_type', d.file_types[index])}</li>)}
                                    </ul>
                                    : t('grievancePortal.no_files')],
                            ])}

                            <Section title={t('grievancePortal.steps.review')}>
                                <div className="space-y-4">
                                    {amending && (
                                        <FormRow label={t('grievancePortal.amendment_reason')} help={t('grievancePortal.amendment_help')} error={form.errors.amendment_reason}>
                                            <textarea className={textareaCls} rows={3} maxLength={2000} value={d.amendment_reason} onChange={(event) => form.setData('amendment_reason', event.target.value)} />
                                        </FormRow>
                                    )}
                                    <label className={cx('flex items-start gap-3 rounded-lg border p-3 text-sm', d.declaration ? 'border-primary bg-primary/5' : 'border-(--app-border)')}>
                                        <input type="checkbox" className="mt-0.5 h-4 w-4 shrink-0 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary" checked={d.declaration} onChange={(event) => form.setData('declaration', event.target.checked)} />
                                        <span className="text-(--app-foreground)">{t('grievancePortal.declaration')}</span>
                                    </label>
                                    <FieldError message={form.errors.declaration} />
                                    {deadline?.late && deadline.force_majeure_due_at && !deadline.force_majeure_open && (
                                        <p className="flex items-start gap-2 text-sm text-red-700 dark:text-red-300" role="alert"><XCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>{fmt(t('grievancePortal.deadline_late_closed'), { date: dateText(deadline.force_majeure_due_at) })}</span></p>
                                    )}
                                </div>
                            </Section>
                        </div>
                    )}

                    {/* Footer actions */}
                    <div className="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-(--app-border) bg-[color-mix(in_srgb,var(--app-surface)_95%,transparent)] px-4 py-3 backdrop-blur-sm">
                        <div>
                            {stepIndex > 0 && <button type="button" className={secondaryBtn} onClick={() => goTo(STEPS[stepIndex - 1])}>{t('grievancePortal.back')}</button>}
                        </div>
                        <div className="flex flex-wrap items-center gap-3">
                            <button type="button" className={secondaryBtn} disabled={form.processing} onClick={() => save(false)}>{form.processing ? t('common.saving') : t('grievancePortal.save_draft')}</button>
                            {step !== 'review'
                                ? <button type="submit" className={primaryBtn}>{t('grievancePortal.next')}</button>
                                : <button type="button" className={primaryBtn} disabled={form.processing} onClick={() => save(true)}>{form.processing ? t('common.saving') : t('grievancePortal.submit')}</button>}
                        </div>
                    </div>
                </form>

                <aside className="space-y-4 xl:sticky xl:top-4">
                    {checklistPanel}
                    {nextStepsPanel}
                </aside>
            </div>
        </Workspace>
    );
}
