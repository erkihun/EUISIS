import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { RecordStatus } from '@/Components/assessmentOversight/RecordStatus';
import { AppFilterBar, Field, Section, Stat, Table, TablePanel, filterInputCls, inputCls, linkBtn, nameOf, pageCls, primaryBtn, secondaryBtn, smallBtn, tdCls, thCls, formatScore, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

type RecordRow = { id: string; status: string; employee_snapshot: { name: string; number: string }; period_start: string; period_end: string; version: { name_en: string; name_am: string | null; version_no: number }; percentage: number | string | null; contribution: number | string | null; evaluators: { submitted: number; total: number } | null };
type Props = {
    records: Paginator<RecordRow>; canManage: boolean;
    forms: { id: string; code: string; name_en: string; name_am: string | null }[];
    employees: { id: string; employee_number: string; full_name: string }[];
    users: { id: number; name: string }[];
    filters: { period_start?: string; period_end?: string; status?: string };
    summary: null | { total: number; awaiting_ratings: number; awaiting_review: number; completed: number; unassessed: number; illness: number; other: number; pending: number };
};

/**
 * Assessment Management › Assessments & Summary: assign a published form,
 * collect peer ratings, review and acknowledge. City-wide figures, result
 * bands and gender live in Assessment Oversight (configured band policy).
 */
export default function AssessmentRecordsIndex({ records, canManage, forms, employees, users, filters, summary }: Props) {
    const { t, locale } = useLocale();
    const [creating, setCreating] = useState(false);
    const STATUSES = ['assigned', 'submitted', 'reviewed', 'acknowledged', 'unassessed'] as const;
    const [period, setPeriod] = useState({ start: filters.period_start ?? '', end: filters.period_end ?? '' });
    const form = useForm({ employee_id: '', form_id: '', period_start: '', period_end: '', reviewer_id: '', evaluator_ids: [] as number[] });
    const title = t('assessments.records.title');

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('assessment-records.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={title} description={t('assessments.records.description')}
            actions={<>
                <Link className={secondaryBtn} href={route('assessment-forms.index')}>{t('assessments.records.forms')}</Link>
                {canManage && !creating && <button type="button" className={primaryBtn} onClick={() => setCreating(true)}>{t('assessments.records.assign')}</button>}
            </>} />}>
            <Head title={title} />
            <div className={pageCls}>
                {canManage && creating && (
                    <Section title={t('assessments.records.assignTitle')} description={t('assessments.records.assignHelp')}>
                        <form onSubmit={submit} className="grid gap-4 md:grid-cols-2">
                            <Field label={t('assessments.records.employee')} htmlFor="record-employee" error={form.errors.employee_id}>
                                <select required id="record-employee" className={inputCls} value={form.data.employee_id} onChange={(e) => form.setData('employee_id', e.target.value)}>
                                    <option value="">{t('assessments.records.selectEmployee')}</option>
                                    {employees.map((e) => <option key={e.id} value={e.id}>{e.employee_number} · {e.full_name}</option>)}
                                </select>
                            </Field>
                            <Field label={t('assessments.records.form')} htmlFor="record-form" error={form.errors.form_id}>
                                <select required id="record-form" className={inputCls} value={form.data.form_id} onChange={(e) => form.setData('form_id', e.target.value)}>
                                    <option value="">{t('assessments.records.selectForm')}</option>
                                    {forms.map((f) => <option key={f.id} value={f.id}>{f.code} · {nameOf(f, locale)}</option>)}
                                </select>
                            </Field>
                            <Field label={t('assessments.records.periodStart')} htmlFor="record-start" error={form.errors.period_start}>
                                <LocalizedDatePicker required id="record-start" className={inputCls} value={form.data.period_start} onChange={(value) => form.setData('period_start', value)} />
                            </Field>
                            <Field label={t('assessments.records.periodEnd')} htmlFor="record-end" error={form.errors.period_end}>
                                <LocalizedDatePicker required id="record-end" min={form.data.period_start} className={inputCls} value={form.data.period_end} onChange={(value) => form.setData('period_end', value)} />
                            </Field>
                            <Field label={t('assessments.records.reviewer')} htmlFor="record-reviewer" error={form.errors.reviewer_id}>
                                <select required id="record-reviewer" className={inputCls} value={form.data.reviewer_id} onChange={(e) => form.setData('reviewer_id', e.target.value)}>
                                    <option value="">{t('assessments.records.selectReviewer')}</option>
                                    {users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}
                                </select>
                            </Field>
                            <fieldset className="md:col-span-2">
                                <legend className="mb-2 text-sm font-medium text-gray-700 dark:text-slate-300">{t('assessments.records.peers')}</legend>
                                <div className="grid max-h-56 gap-2 overflow-auto rounded-lg border border-gray-200 p-3 sm:grid-cols-2 dark:border-slate-700">
                                    {users.map((u) => (
                                        <label key={u.id} className="flex items-center gap-2 text-sm">
                                            <input type="checkbox" checked={form.data.evaluator_ids.includes(Number(u.id))}
                                                onChange={(e) => form.setData('evaluator_ids', e.target.checked ? [...form.data.evaluator_ids, Number(u.id)] : form.data.evaluator_ids.filter((id) => id !== Number(u.id)))} />
                                            {u.name}
                                        </label>
                                    ))}
                                </div>
                                {form.errors.evaluator_ids && <p className="mt-1 text-sm text-red-600">{form.errors.evaluator_ids}</p>}
                            </fieldset>
                            <div className="flex gap-2 md:col-span-2">
                                <button disabled={form.processing} className={primaryBtn}>{t('assessments.records.create')}</button>
                                <button type="button" className={secondaryBtn} onClick={() => setCreating(false)}>{t('assessments.records.cancel')}</button>
                            </div>
                        </form>
                    </Section>
                )}

                <AppFilterBar routeName="assessment-records.index" filters={filters} onBeforeSubmit={(params) => ({ ...params, period_start: period.start, period_end: period.end })}>
                    <div className="w-44"><LocalizedDatePicker id="filter-start" className={filterInputCls} value={period.start} onChange={(value) => setPeriod({ ...period, start: value })} placeholder={t('assessments.records.periodStart')} /></div>
                    <div className="w-44"><LocalizedDatePicker id="filter-end" className={filterInputCls} min={period.start} value={period.end} onChange={(value) => setPeriod({ ...period, end: value })} placeholder={t('assessments.records.periodEnd')} /></div>
                    <select name="status" aria-label={t('assessments.records.status')} className={filterInputCls} defaultValue={filters.status ?? ''}>
                        <option value="">{t('assessments.records.allStatuses')}</option>
                        {STATUSES.map((status) => <option key={status} value={status}>{t(`assessmentOversight.recordStatuses.${status}`)}</option>)}
                    </select>
                </AppFilterBar>

                {summary && (
                    <Section title={t('assessments.records.summaryTitle')} description={t('assessments.records.summaryHelp')}
                        actions={<Link className={smallBtn} href={route('assessment-oversight.dashboard')}>{t('assessments.records.openOversight')}</Link>}>
                        <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                            <Stat label={t('assessments.records.assigned')} value={summary.total} />
                            <Stat label={t('assessments.records.awaitingRatings')} value={summary.awaiting_ratings} />
                            <Stat label={t('assessments.records.awaitingReview')} value={summary.awaiting_review} />
                            <Stat label={t('assessments.records.completed')} value={summary.completed} />
                            <Stat label={t('assessments.records.unassessed')} value={summary.unassessed}
                                hint={summary.unassessed > 0 ? `${t('assessments.records.illness')}: ${summary.illness} · ${t('assessments.records.otherReason')}: ${summary.other}` : undefined} />
                        </div>
                    </Section>
                )}

                <TablePanel page={records} empty={t('assessments.records.empty')}>
                    <Table head={<>
                        <th className={thCls}>{t('assessments.records.employee')}</th>
                        <th className={thCls}>{t('assessments.records.formPeriod')}</th>
                        <th className={thCls}>{t('assessments.records.evaluators')}</th>
                        <th className={thCls}>{t('assessments.records.status')}</th>
                        <th className={thCls}>{t('assessments.records.peerResult')}</th>
                        <th className={thCls}><span className="sr-only">{t('assessments.records.actions')}</span></th>
                    </>}>
                        {records.data.map((r) => (
                            <tr key={r.id}>
                                <td className={tdCls}>{r.employee_snapshot.name}<div className="text-xs text-gray-500">{r.employee_snapshot.number}</div></td>
                                <td className={tdCls}>{nameOf(r.version, locale)} · v{r.version.version_no}<div className="text-xs text-gray-500"><LocalizedDateDisplay value={r.period_start} /> – <LocalizedDateDisplay value={r.period_end} /></div></td>
                                <td className={`${tdCls} tabular-nums`}>{r.evaluators ? `${r.evaluators.submitted}/${r.evaluators.total}` : '—'}</td>
                                <td className={tdCls}><RecordStatus value={r.status} /></td>
                                <td className={`${tdCls} tabular-nums`}>{r.percentage == null ? '—' : `${formatScore(r.percentage)}%`}{r.contribution != null && <div className="text-xs text-gray-500">{t('assessments.records.contribution')}: {formatScore(r.contribution)}</div>}</td>
                                <td className={`${tdCls} text-right`}><Link className={linkBtn} href={route('assessment-records.show', r.id)}>{t('assessments.records.open')}</Link></td>
                            </tr>
                        ))}
                    </Table>
                </TablePanel>
            </div>
        </AuthenticatedLayout>
    );
}
