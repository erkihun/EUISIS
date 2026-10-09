import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { AppFilterBar, filterInputCls, pageCls, Stat, Table, TablePanel, tdCls, thCls } from '@/Components/performance/ui';
import { DeadlineBadge, ProgressMeter, RecordStatusBadge, ResponseStatus, usePick } from '@/Components/assessmentWorkspace/kit';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Option = { id: string; code?: string; name_en: string | null; name_am: string | null };
type Row = { id: string; status: string; evaluator_type: string; due_at: string | null; deadline: string | null; record_status: string; employee: { name?: string; name_en?: string; number?: string; position?: string; position_am?: string; unit?: string; unit_am?: string }; form: { name_en: string; name_am: string | null; version_no: number } | null; cycle: Option | null; progress: { answered: number; required: number } };
type Page<T> = { data: T[]; current_page: number; last_page: number; per_page: number; from: number | null; to: number | null; total: number; path: string; prev_page_url: string | null; next_page_url: string | null };
type Props = { rows: Page<Row>; filters: { cycle?: string; status?: string; form?: string; unit?: string }; counts: Record<string, number>; options: { cycles: Option[]; forms: Option[]; units: Option[] } };

export default function AssignedAssessments({ rows, filters, counts, options }: Props) {
    const { t, locale } = useLocale();
    const pick = usePick();
    const label = (value: Option | null) => value ? (locale === 'am' ? value.name_am || value.name_en : value.name_en || value.name_am) : '—';
    const employee = (value: Row['employee']) => (locale === 'am' ? value.name || value.name_en : value.name_en || value.name) || '—';
    const kpis = ['assigned', 'not_started', 'in_progress', 'due_soon', 'overdue', 'submitted', 'returned', 'completed'] as const;

    return <AuthenticatedLayout header={<PageHeader title={t('assessmentWorkspace.title')} description={t('assessmentWorkspace.description')} />}>
        <Head title={t('assessmentWorkspace.title')} />
        <div className={pageCls}>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-8">
                {kpis.map((key) => <Stat key={key} label={t(`assessmentWorkspace.kpi.${key}`)} value={counts[key] ?? 0} />)}
            </div>
            <AppFilterBar routeName="assessment-workspace.index" filters={filters}>
                <select name="cycle" aria-label={t('assessmentWorkspace.filters.cycle')} className={filterInputCls} defaultValue={filters.cycle ?? ''}><option value="">{t('assessmentWorkspace.filters.allCycles')}</option>{options.cycles.map((value) => <option key={value.id} value={value.id}>{label(value)}</option>)}</select>
                <select name="status" aria-label={t('assessmentWorkspace.filters.status')} className={filterInputCls} defaultValue={filters.status ?? ''}><option value="">{t('assessmentWorkspace.filters.allStatuses')}</option>{['not_started', 'in_progress', 'returned', 'submitted', 'due_soon', 'overdue', 'completed'].map((value) => <option key={value} value={value}>{t(`assessmentWorkspace.${['due_soon', 'overdue'].includes(value) ? 'deadline' : 'statuses'}.${value}`)}</option>)}</select>
                <select name="form" aria-label={t('assessmentWorkspace.filters.form')} className={filterInputCls} defaultValue={filters.form ?? ''}><option value="">{t('assessmentWorkspace.filters.allForms')}</option>{options.forms.map((value) => <option key={value.id} value={value.id}>{label(value)}</option>)}</select>
                <select name="unit" aria-label={t('assessmentWorkspace.filters.unit')} className={filterInputCls} defaultValue={filters.unit ?? ''}><option value="">{t('assessmentWorkspace.filters.allUnits')}</option>{options.units.map((value) => <option key={value.id} value={value.id}>{label(value)}</option>)}</select>
            </AppFilterBar>
            <TablePanel page={rows} empty={t('assessmentWorkspace.empty')}>
                <Table head={<><th className={thCls}>{t('assessmentWorkspace.columns.employee')}</th><th className={thCls}>{t('assessmentWorkspace.columns.form')}</th><th className={thCls}>{t('assessmentWorkspace.columns.cycle')}</th><th className={thCls}>{t('assessmentWorkspace.columns.evaluatorType')}</th><th className={thCls}>{t('assessmentWorkspace.columns.dueDate')}</th><th className={thCls}>{t('assessmentWorkspace.columns.status')}</th><th className={thCls}>{t('assessmentWorkspace.columns.progress')}</th><th className={thCls}><span className="sr-only">{t('assessmentWorkspace.columns.action')}</span></th></>}>
                    {rows.data.map((row) => <tr key={row.id}><td className={tdCls}><p className="font-medium">{employee(row.employee)}</p><p className="text-xs text-gray-500">{row.employee.number}</p><p className="text-xs text-gray-500">{pick(row.employee.unit, row.employee.unit_am)} · {pick(row.employee.position, row.employee.position_am)}</p></td><td className={tdCls}>{row.form ? `${pick(row.form.name_en, row.form.name_am)} · v${row.form.version_no}` : '—'}</td><td className={tdCls}>{label(row.cycle)}</td><td className={tdCls}>{t(`assessmentWorkspace.evaluatorTypes.${row.evaluator_type}`)}</td><td className={tdCls}>{row.due_at ? <><LocalizedDateDisplay value={row.due_at} /><div className="mt-1"><DeadlineBadge value={row.deadline} /></div></> : '—'}</td><td className={tdCls}><ResponseStatus value={row.status} /><div className="mt-1"><RecordStatusBadge value={row.record_status} /></div></td><td className={tdCls}><ProgressMeter {...row.progress} /></td><td className={`${tdCls} text-right`}><Link className="text-xs font-medium text-[color:var(--color-primary)]" href={route('assessment-workspace.show', row.id)}>{row.status === 'not_started' ? t('assessmentWorkspace.open') : t('assessmentWorkspace.continue')}</Link></td></tr>)}
                </Table>
            </TablePanel>
        </div>
    </AuthenticatedLayout>;
}
