import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { plain } from '@/Components/dailyActivity/WorkExecution';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

/*
 * Position Service (ዋና አገልግሎት) → Sub-Service (ንዑስ አገልግሎት) → Main Task
 * (ዋና ተግባር) → Task Standard / BPR plan (ስታንዳርድ መለኪያ / የBPR ዕቅድ).
 *
 * Master data for the Employee Daily Plan & Work Execution Register.
 * Standards are versioned: a draft can be edited, an approved version never
 * is. Approving a version closes the one before it, so recorded work always
 * keeps the standard it was measured against.
 */

type Standard = {
    id: string;
    version_no: number;
    status: 'draft' | 'approved' | 'retired';
    standard_measure: string | null;
    bpr_reference: string | null;
    planned_quantity: string | null;
    quantity_unit: string | null;
    planned_time_minutes: string | null;
    planned_quality: string | null;
    quality_unit: string | null;
    quality_measure: string | null;
    quality_source: 'employee' | 'reviewer';
    effective_from: string | null;
    effective_to: string | null;
    approved_at: string | null;
};

type Node = { id: string; code: string; name_en: string; name_am: string | null; description: string | null; is_active: boolean; sort_order: number };
type Task = Node & { standards: Standard[] };
type SubService = Node & { tasks: Task[] };

type Props = {
    service: {
        id: string;
        service_no: string;
        name_en: string;
        name_am: string | null;
        position: { title_en: string; title_am: string | null } | null;
        organization: { name_en: string; name_am: string | null } | null;
    };
    sub_services: SubService[];
    quality_sources: string[];
    can: { manage: boolean; approve: boolean };
};

type Editing =
    | { kind: 'sub'; record?: SubService }
    | { kind: 'task'; parent: SubService; record?: Task }
    | { kind: 'standard'; task: Task; record?: Standard }
    | null;

const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'mb-1 block text-xs font-medium text-gray-700 dark:text-slate-300';
const primaryBtn = 'inline-flex min-h-9 items-center justify-center rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50';
const secondaryBtn = 'inline-flex min-h-9 items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200';
const linkBtn = 'text-xs font-medium text-[color:var(--color-primary)] hover:underline';
const dangerLink = 'text-xs font-medium text-red-600 hover:underline dark:text-red-400';

const STATUS_CLS: Record<Standard['status'], string> = {
    draft: 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300',
    approved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
    retired: 'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400',
};

export default function Structure({ service, sub_services, quality_sources, can }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [editing, setEditing] = useState<Editing>(null);
    const name = (node: { name_en: string; name_am: string | null }) => (locale === 'am' && node.name_am) || node.name_en;

    async function act(method: 'delete' | 'post', url: string, title: string, description: string) {
        const { confirmed } = await confirm({ title, description, confirmLabel: title, cancelLabel: t('common.cancel'), variant: method === 'delete' ? 'danger' : 'warning' });
        if (confirmed) router.visit(url, { method, preserveScroll: true });
    }

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={`${service.service_no} · ${name(service)}`}
                    description={[service.position && ((locale === 'am' && service.position.title_am) || service.position.title_en), service.organization && name(service.organization)].filter(Boolean).join(' · ')}
                    actions={<Link href={route('position-services.index')} className={secondaryBtn}>{t('workStandards.back')}</Link>}
                />
            )}
        >
            <Head title={t('workStandards.title')} />
            <div className="space-y-4">
                <p className="max-w-3xl text-sm text-gray-600 dark:text-slate-400">{t('workStandards.intro')}</p>

                {can.manage && editing?.kind !== 'sub' && (
                    <button type="button" className={primaryBtn} onClick={() => setEditing({ kind: 'sub' })}>{t('workStandards.addSubService')}</button>
                )}
                {editing?.kind === 'sub' && (
                    <NodeForm
                        title={editing.record ? t('workStandards.editSubService') : t('workStandards.addSubService')}
                        record={editing.record}
                        url={editing.record ? route('work-structure.sub-services.update', editing.record.id) : route('work-structure.sub-services.store', service.id)}
                        method={editing.record ? 'patch' : 'post'}
                        onDone={() => setEditing(null)}
                    />
                )}

                {sub_services.length === 0 && (
                    <p className="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-slate-700 dark:text-slate-400">{t('workStandards.noSubServices')}</p>
                )}

                {sub_services.map((sub) => (
                    <section key={sub.id} className="rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <header className="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 px-4 py-3 dark:border-slate-800">
                            <div className="min-w-0">
                                <p className="text-xs uppercase tracking-wide text-gray-500 dark:text-slate-400">{t('workStandards.subService')} · {sub.code}</p>
                                <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100">{name(sub)} {!sub.is_active && <InactiveBadge />}</h2>
                                {sub.description && <p className="mt-1 text-sm text-gray-600 dark:text-slate-400">{sub.description}</p>}
                            </div>
                            {can.manage && (
                                <div className="flex flex-wrap items-center gap-3">
                                    <button type="button" className={linkBtn} onClick={() => setEditing({ kind: 'task', parent: sub })}>{t('workStandards.addTask')}</button>
                                    <button type="button" className={linkBtn} onClick={() => setEditing({ kind: 'sub', record: sub })}>{t('common.edit')}</button>
                                    <button type="button" className={dangerLink} onClick={() => act('delete', route('work-structure.sub-services.destroy', sub.id), t('common.delete'), t('workStandards.deleteSubConfirm'))}>{t('common.delete')}</button>
                                </div>
                            )}
                        </header>

                        {editing?.kind === 'task' && editing.parent.id === sub.id && (
                            <div className="p-4">
                                <NodeForm
                                    title={editing.record ? t('workStandards.editTask') : t('workStandards.addTask')}
                                    record={editing.record}
                                    url={editing.record ? route('work-structure.tasks.update', editing.record.id) : route('work-structure.tasks.store', sub.id)}
                                    method={editing.record ? 'patch' : 'post'}
                                    onDone={() => setEditing(null)}
                                />
                            </div>
                        )}

                        {sub.tasks.length === 0 ? (
                            <p className="px-4 py-4 text-sm text-gray-500 dark:text-slate-400">{t('workStandards.noTasks')}</p>
                        ) : (
                            <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                                {sub.tasks.map((task) => (
                                    <li key={task.id} className="px-4 py-3">
                                        <div className="flex flex-wrap items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="text-xs text-gray-500 dark:text-slate-400">{t('workStandards.mainTask')} · {task.code}</p>
                                                <p className="font-medium text-gray-900 dark:text-slate-100">{name(task)} {!task.is_active && <InactiveBadge />}</p>
                                            </div>
                                            {can.manage && (
                                                <div className="flex flex-wrap items-center gap-3">
                                                    <button type="button" className={linkBtn} onClick={() => setEditing({ kind: 'standard', task })}>{t('workStandards.newVersion')}</button>
                                                    <button type="button" className={linkBtn} onClick={() => setEditing({ kind: 'task', parent: sub, record: task })}>{t('common.edit')}</button>
                                                    <button type="button" className={dangerLink} onClick={() => act('delete', route('work-structure.tasks.destroy', task.id), t('common.delete'), t('workStandards.deleteTaskConfirm'))}>{t('common.delete')}</button>
                                                </div>
                                            )}
                                        </div>

                                        {editing?.kind === 'standard' && editing.task.id === task.id && (
                                            <div className="mt-3">
                                                <StandardForm
                                                    record={editing.record}
                                                    qualitySources={quality_sources}
                                                    url={editing.record ? route('work-structure.standards.update', editing.record.id) : route('work-structure.standards.store', task.id)}
                                                    method={editing.record ? 'patch' : 'post'}
                                                    onDone={() => setEditing(null)}
                                                />
                                            </div>
                                        )}

                                        {task.standards.length === 0 ? (
                                            <p className="mt-2 text-sm text-amber-700 dark:text-amber-300">{t('workStandards.noStandard')}</p>
                                        ) : (
                                            <div className="mt-2 overflow-x-auto">
                                                <table className="min-w-full text-sm">
                                                    <thead>
                                                        <tr className="text-left text-xs text-gray-500 dark:text-slate-400">
                                                            <th className="py-1 pe-3 font-medium">{t('workStandards.version')}</th>
                                                            <th className="py-1 pe-3 font-medium">{t('workStandards.plan')}</th>
                                                            <th className="py-1 pe-3 font-medium">{t('workStandards.effective')}</th>
                                                            <th className="py-1 pe-3 font-medium">{t('workStandards.status')}</th>
                                                            <th className="py-1 font-medium"><span className="sr-only">{t('common.actions')}</span></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                                        {task.standards.map((standard) => (
                                                            <tr key={standard.id} className="align-top">
                                                                <td className="py-2 pe-3 tabular-nums">v{standard.version_no}</td>
                                                                <td className="py-2 pe-3">
                                                                    <PlanSummary standard={standard} />
                                                                </td>
                                                                <td className="whitespace-nowrap py-2 pe-3 text-xs">
                                                                    <LocalizedDateDisplay value={standard.effective_from} /> – {standard.effective_to ? <LocalizedDateDisplay value={standard.effective_to} /> : t('workStandards.openEnded')}
                                                                </td>
                                                                <td className="py-2 pe-3">
                                                                    <span className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ${STATUS_CLS[standard.status]}`}>{t(`workStandards.statuses.${standard.status}`)}</span>
                                                                </td>
                                                                <td className="whitespace-nowrap py-2 text-right">
                                                                    {standard.status === 'draft' && can.manage && <>
                                                                        <button type="button" className={`${linkBtn} me-3`} onClick={() => setEditing({ kind: 'standard', task, record: standard })}>{t('common.edit')}</button>
                                                                        <button type="button" className={`${dangerLink} me-3`} onClick={() => act('delete', route('work-structure.standards.destroy', standard.id), t('common.delete'), t('workStandards.deleteDraftConfirm'))}>{t('common.delete')}</button>
                                                                    </>}
                                                                    {standard.status === 'draft' && can.approve && (
                                                                        <button type="button" className={linkBtn} onClick={() => act('post', route('work-structure.standards.approve', standard.id), t('workStandards.approve'), t('workStandards.approveConfirm'))}>{t('workStandards.approve')}</button>
                                                                    )}
                                                                    {standard.status === 'approved' && can.approve && (
                                                                        <button type="button" className={dangerLink} onClick={() => act('post', route('work-structure.standards.retire', standard.id), t('workStandards.retire'), t('workStandards.retireConfirm'))}>{t('workStandards.retire')}</button>
                                                                    )}
                                                                </td>
                                                            </tr>
                                                        ))}
                                                    </tbody>
                                                </table>
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}

function InactiveBadge() {
    const { t } = useLocale();
    return <span className="ms-1 rounded bg-gray-100 px-1.5 py-0.5 align-middle text-[11px] font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-400">{t('workStandards.inactive')}</span>;
}

function PlanSummary({ standard }: { standard: Standard }) {
    const { t } = useLocale();
    const parts = [
        standard.planned_quantity !== null && `${t('workStandards.quantity')}: ${plain(standard.planned_quantity)} ${standard.quantity_unit ?? ''}`.trim(),
        standard.planned_time_minutes !== null && `${t('workStandards.time')}: ${plain(standard.planned_time_minutes)} ${t('dailyActivities.work.minutes')}`,
        standard.planned_quality !== null && `${t('workStandards.quality')}: ${plain(standard.planned_quality)} ${standard.quality_unit ?? ''}`.trim(),
    ].filter(Boolean);

    return (
        <div className="space-y-0.5">
            <p className="text-gray-900 dark:text-slate-100">{parts.join(' · ')}</p>
            {standard.standard_measure && <p className="text-xs text-gray-500 dark:text-slate-400">{standard.standard_measure}</p>}
            {standard.planned_quality !== null && (
                <p className="text-xs text-gray-500 dark:text-slate-400">{t(`workStandards.qualitySources.${standard.quality_source}`)}{standard.quality_measure ? ` · ${standard.quality_measure}` : ''}</p>
            )}
            {standard.bpr_reference && <p className="text-xs text-gray-500 dark:text-slate-400">BPR: {standard.bpr_reference}</p>}
        </div>
    );
}

function NodeForm({ title, record, url, method, onDone }: { title: string; record?: Node; url: string; method: 'post' | 'patch'; onDone: () => void }) {
    const { t } = useLocale();
    const form = useForm({
        code: record?.code ?? '',
        name_en: record?.name_en ?? '',
        name_am: record?.name_am ?? '',
        description: record?.description ?? '',
        is_active: record?.is_active ?? true,
        sort_order: record?.sort_order ?? 0,
    });
    const errors = form.errors as Record<string, string | undefined>;

    function submit(e: FormEvent) {
        e.preventDefault();
        form.submit(method, url, { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <form onSubmit={submit} className="space-y-3 rounded-xl border border-[color:var(--color-primary)]/30 bg-[color:var(--color-primary)]/5 p-4">
            <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h3>
            <div className="grid gap-3 sm:grid-cols-[8rem_1fr_1fr]">
                <Field label={t('workStandards.code')} error={errors.code}>
                    <input className={inputCls} value={form.data.code} maxLength={40} required onChange={(e) => form.setData('code', e.target.value)} />
                </Field>
                <Field label={t('workStandards.nameEn')} error={errors.name_en}>
                    <input className={inputCls} value={form.data.name_en} maxLength={255} required onChange={(e) => form.setData('name_en', e.target.value)} />
                </Field>
                <Field label={t('workStandards.nameAm')} error={errors.name_am}>
                    <input className={inputCls} value={form.data.name_am} maxLength={255} onChange={(e) => form.setData('name_am', e.target.value)} />
                </Field>
            </div>
            <Field label={t('workStandards.description')} error={errors.description}>
                <textarea rows={2} className={inputCls} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
            </Field>
            <div className="flex flex-wrap items-center gap-4">
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                    <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} className="h-4 w-4 rounded border-gray-300" />
                    {t('workStandards.active')}
                </label>
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                    {t('workStandards.sortOrder')}
                    <input type="number" min={0} className={`${inputCls} w-24`} value={form.data.sort_order} onChange={(e) => form.setData('sort_order', Number(e.target.value))} />
                </label>
            </div>
            {errors.structure && <p role="alert" className="text-sm text-red-700 dark:text-red-400">{errors.structure}</p>}
            <div className="flex gap-2">
                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('common.save')}</button>
                <button type="button" className={secondaryBtn} onClick={onDone}>{t('common.cancel')}</button>
            </div>
        </form>
    );
}

function StandardForm({ record, qualitySources, url, method, onDone }: { record?: Standard; qualitySources: string[]; url: string; method: 'post' | 'patch'; onDone: () => void }) {
    const { t } = useLocale();
    const form = useForm({
        standard_measure: record?.standard_measure ?? '',
        bpr_reference: record?.bpr_reference ?? '',
        planned_quantity: record?.planned_quantity ?? '',
        quantity_unit: record?.quantity_unit ?? '',
        planned_time_minutes: record?.planned_time_minutes ?? '',
        planned_quality: record?.planned_quality ?? '',
        quality_unit: record?.quality_unit ?? '',
        quality_measure: record?.quality_measure ?? '',
        quality_source: record?.quality_source ?? 'employee',
        effective_from: record?.effective_from ?? '',
        effective_to: record?.effective_to ?? '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const measuresQuality = form.data.planned_quality !== '' && form.data.planned_quality !== null;

    function submit(e: FormEvent) {
        e.preventDefault();
        form.submit(method, url, { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <form onSubmit={submit} className="space-y-3 rounded-xl border border-[color:var(--color-primary)]/30 bg-[color:var(--color-primary)]/5 p-4">
            <div>
                <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{record ? t('workStandards.editDraft') : t('workStandards.newVersion')}</h3>
                <p className="mt-1 text-xs text-gray-600 dark:text-slate-400">{t('workStandards.standardHelp')}</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('workStandards.standardMeasure')} error={errors.standard_measure}>
                    <input className={inputCls} value={form.data.standard_measure} maxLength={500} onChange={(e) => form.setData('standard_measure', e.target.value)} />
                </Field>
                <Field label={t('workStandards.bprReference')} error={errors.bpr_reference}>
                    <input className={inputCls} value={form.data.bpr_reference} maxLength={255} onChange={(e) => form.setData('bpr_reference', e.target.value)} />
                </Field>
            </div>
            <fieldset className="grid gap-3 sm:grid-cols-3">
                <legend className="sr-only">{t('workStandards.plan')}</legend>
                <div className="space-y-2">
                    <Field label={t('workStandards.plannedQuantity')} error={errors.planned_quantity}>
                        <input type="number" min={0} step="any" className={inputCls} value={form.data.planned_quantity} onChange={(e) => form.setData('planned_quantity', e.target.value)} />
                    </Field>
                    <Field label={t('workStandards.quantityUnit')} error={errors.quantity_unit}>
                        <input className={inputCls} value={form.data.quantity_unit} maxLength={64} onChange={(e) => form.setData('quantity_unit', e.target.value)} />
                    </Field>
                </div>
                <Field label={t('workStandards.plannedTime')} error={errors.planned_time_minutes}>
                    <input type="number" min={0} step="any" className={inputCls} value={form.data.planned_time_minutes} onChange={(e) => form.setData('planned_time_minutes', e.target.value)} />
                </Field>
                <div className="space-y-2">
                    <Field label={t('workStandards.plannedQuality')} error={errors.planned_quality}>
                        <input type="number" min={0} step="any" className={inputCls} value={form.data.planned_quality} onChange={(e) => form.setData('planned_quality', e.target.value)} />
                    </Field>
                    <Field label={t('workStandards.qualityUnit')} error={errors.quality_unit}>
                        <input className={inputCls} value={form.data.quality_unit} maxLength={64} onChange={(e) => form.setData('quality_unit', e.target.value)} />
                    </Field>
                </div>
            </fieldset>
            {measuresQuality && (
                <div className="grid gap-3 sm:grid-cols-[1fr_14rem]">
                    <Field label={t('workStandards.qualityMeasure')} error={errors.quality_measure} help={t('workStandards.qualityMeasureHelp')}>
                        <textarea rows={2} className={inputCls} value={form.data.quality_measure} onChange={(e) => form.setData('quality_measure', e.target.value)} />
                    </Field>
                    <Field label={t('workStandards.qualitySource')} error={errors.quality_source}>
                        <select className={inputCls} value={form.data.quality_source} onChange={(e) => form.setData('quality_source', e.target.value as 'employee' | 'reviewer')}>
                            {qualitySources.map((source) => <option key={source} value={source}>{t(`workStandards.qualitySources.${source}`)}</option>)}
                        </select>
                    </Field>
                </div>
            )}
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('workStandards.effectiveFrom')} error={errors.effective_from}>
                    <LocalizedDatePicker value={form.data.effective_from} onChange={(value) => form.setData('effective_from', value)} required className={inputCls} />
                </Field>
                <Field label={t('workStandards.effectiveTo')} error={errors.effective_to}>
                    <LocalizedDatePicker value={form.data.effective_to} onChange={(value) => form.setData('effective_to', value)} className={inputCls} />
                </Field>
            </div>
            {errors.status && <p role="alert" className="text-sm text-red-700 dark:text-red-400">{errors.status}</p>}
            <div className="flex gap-2">
                <button type="submit" className={primaryBtn} disabled={form.processing}>{t('workStandards.saveDraft')}</button>
                <button type="button" className={secondaryBtn} onClick={onDone}>{t('common.cancel')}</button>
            </div>
        </form>
    );
}

function Field({ label, error, help, children }: { label: string; error?: string; help?: string; children: JSX.Element }) {
    return (
        // The wrapping label names its control for screen readers.
        <label className="block">
            <span className={labelCls}>{label}</span>
            {children}
            {help && !error && <span className="mt-1 block text-xs text-gray-500 dark:text-slate-400">{help}</span>}
            {error && <span role="alert" className="mt-1 block text-xs text-red-700 dark:text-red-400">{error}</span>}
        </label>
    );
}
