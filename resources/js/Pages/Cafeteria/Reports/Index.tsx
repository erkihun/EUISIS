import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import { Head, Link, useForm, router, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

import { buttonClassName, cx, inputClassName, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
type ReportRun = {
    id: string;
    report_number: string;
    report_type: string;
    period_start: string;
    period_end: string;
    status: string;
    generated_at: string;
    totals: Record<string, number> | null;
    can: { export: boolean };
    organization?: { name_en: string; name_am?: string } | null;
};

type Meta = { current_page: number; last_page: number; total: number };

export default function ReportsIndex({
    reports,
    meta,
    filters,
    can,
    organizations,
    requiresOrganization,
}: {
    reports: ReportRun[];
    meta: Meta;
    filters: Record<string, string>;
    can: { generate: boolean };
    organizations: { id: string; name_en: string; name_am?: string }[];
    requiresOrganization: boolean;
}) {
    const { t, locale } = useLocale();
    const { errors } = usePage().props;
    const money = new Intl.NumberFormat(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const [showForm, setShowForm] = useState(false);
    const form = useForm({
        report_type: 'monthly',
        period_start: '',
        organization_id: '',
    });

    const inputCls = cx(inputClassName, 'w-full');

    const reportTypeLabel = (s: string) => ({
        daily:   t('cafeteria.daily'),
        weekly:  t('cafeteria.weekly'),
        monthly: t('cafeteria.monthly'),
    } as Record<string, string>)[s] ?? s;

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('cafeteria.reports.generate'));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.reports')}
                    description={t('cafeteria.reportsDescription')}
                    actions={
                        can.generate ? (
                            <button
                                onClick={() => setShowForm(!showForm)}
                                className={buttonClassName({ variant: 'primary' })}
                            >
                                {t('cafeteria.generateReport')}
                            </button>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('cafeteria.reports')} />

            <div className="w-full space-y-6">
                {can.generate && (showForm || Object.keys(form.errors).length > 0) && (
                    <form
                        onSubmit={submit}
                        className="rounded-card border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950/30"
                    >
                        <h3 className="mb-4 text-sm font-semibold text-[color:var(--app-foreground)]">{t('cafeteria.generateReport')}</h3>
                        <p className="mb-4 text-sm text-[color:var(--app-muted-foreground)]">{t('cafeteria.reportPeriodHelp')}</p>
                        <fieldset disabled={form.processing} className="grid min-w-0 gap-4 sm:grid-cols-3">
                            <div className="space-y-1">
                                <label htmlFor="report-type" className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('cafeteria.reportType')}</label>
                                <select id="report-type" className={inputCls} value={form.data.report_type} onChange={(e) => form.setData('report_type', e.target.value)}>
                                    <option value="daily">{t('cafeteria.daily')}</option>
                                    <option value="monthly">{t('cafeteria.monthly')}</option>
                                </select>
                            </div>
                            <div className="space-y-1">
                                <label htmlFor="report-date" className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('cafeteria.reportAnchorDate')}</label>
                                <LocalizedDatePicker id="report-date" className={inputCls} value={form.data.period_start} onChange={(iso) => form.setData('period_start', iso)} required />
                            </div>
                            <div className="space-y-1">
                                <label htmlFor="report-organization" className="text-xs font-medium text-[color:var(--app-muted-foreground)]">{t('employees.organization')}</label>
                                <select id="report-organization" className={inputCls} required={requiresOrganization} value={form.data.organization_id} onChange={event => form.setData('organization_id', event.target.value)}><option value="">{requiresOrganization ? t('cafeteria.reportChooseOrganization') : t('common.all')}</option>{organizations.map(org => <option key={org.id} value={org.id}>{locale === 'am' ? org.name_am || org.name_en : org.name_en}</option>)}</select>
                            </div>
                        </fieldset>
                        {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="mt-2 text-sm text-red-600 dark:text-red-400">{error}</p>)}
                        <div className="mt-4 flex gap-3">
                            <button type="submit" disabled={form.processing} className={buttonClassName({ variant: 'primary' })}>
                                {form.processing ? t('common.saving') : t('cafeteria.generateReport')}
                            </button>
                            <button type="button" onClick={() => setShowForm(false)} className={buttonClassName({ variant: 'outline' })}>
                                {t('common.cancel')}
                            </button>
                        </div>
                    </form>
                )}

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <h2 className="font-semibold text-[color:var(--app-foreground)]">{t('cafeteria.reportHistory')}</h2>
                    <form className="mt-4 grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_auto]" onSubmit={event => { event.preventDefault(); router.get(route('cafeteria.reports.index'), Object.fromEntries(new FormData(event.currentTarget)) as Record<string, string>, { preserveScroll: true }); }}>
                        <label className="space-y-2 text-sm text-[color:var(--app-muted-foreground)]"><span>{t('cafeteria.reportType')}</span><select name="type" defaultValue={filters.type ?? ''} className={inputCls}><option value="">{t('common.all')}</option>{['daily', 'weekly', 'monthly'].map(type => <option key={type} value={type}>{reportTypeLabel(type)}</option>)}</select></label>
                        <label className="space-y-2 text-sm text-[color:var(--app-muted-foreground)]"><span>{t('employees.organization')}</span><select name="organization_id" defaultValue={filters.organization_id ?? ''} className={inputCls}><option value="">{t('common.all')}</option>{organizations.map(org => <option key={org.id} value={org.id}>{locale === 'am' ? org.name_am || org.name_en : org.name_en}</option>)}</select></label>
                        <div className="flex gap-2"><button type="button" onClick={() => router.get(route('cafeteria.reports.index'))} className={buttonClassName({ variant: 'outline' })}>{t('common.clear')}</button><button type="submit" className={buttonClassName({ variant: 'primary' })}>{t('common.filter')}</button></div>
                    </form>
                    {!showForm && Object.entries(errors).map(([key, error]) => <p key={key} role="alert" className="mt-2 text-sm text-red-600">{error}</p>)}
                </section>

                <div className="overflow-hidden rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]">
                    <div className="flex flex-wrap justify-between gap-3 border-b border-[color:var(--app-border)] p-5"><h2 className="font-semibold text-[color:var(--app-foreground)]">{t('cafeteria.reports')}</h2><span className="text-sm text-[color:var(--app-muted-foreground)]">{t('cafeteria.reportCount').replace(':count', String(meta.total))}</span></div>
                    {reports.length === 0 ? (
                        <EmptyState title={t('common.noResults')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClassName}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.reportNumber')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.reportType')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('employees.organization')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.periodStart')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('cafeteria.periodEnd')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-right')}>{t('cafeteria.totalSubsidy')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')}>{t('common.generatedAt')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')} />
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {reports.map((rpt) => (
                                        <tr key={rpt.id} className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-foreground)]')}>{rpt.report_number}</td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>{reportTypeLabel(rpt.report_type)}</td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}>{(locale === 'am' ? rpt.organization?.name_am || rpt.organization?.name_en : rpt.organization?.name_en) ?? t('common.all')}</td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={rpt.period_start} /></td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={rpt.period_end} /></td>
                                            <td className={cx(tableCellClassName, 'whitespace-nowrap text-right font-medium tabular-nums text-emerald-600')}>{rpt.totals?.total_subsidy != null ? money.format(rpt.totals.total_subsidy) + ' ETB' : '—'}</td>
                                            <td className={cx(tableCellClassName, 'text-[color:var(--app-muted-foreground)]')}><LocalizedDateDisplay value={rpt.generated_at} withTime /></td>
                                            <td className={cx(tableCellClassName, 'text-right')}>
                                                <Link href={route('cafeteria.reports.show', rpt.id)} className="text-xs text-[color:var(--color-primary)] hover:underline">
                                                    {t('common.view')}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <nav aria-label={t('cafeteria.reportHistory')} className="flex items-center justify-between border-t border-[color:var(--app-border)] p-4"><button type="button" disabled={meta.current_page <= 1} onClick={() => router.get(route('cafeteria.reports.index'), { ...filters, page: meta.current_page - 1 }, { preserveScroll: true })} className={buttonClassName({ variant: 'outline' })}>{t('common.previous')}</button><span className="text-sm text-[color:var(--app-muted-foreground)]">{meta.current_page} / {meta.last_page}</span><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => router.get(route('cafeteria.reports.index'), { ...filters, page: meta.current_page + 1 }, { preserveScroll: true })} className={buttonClassName({ variant: 'outline' })}>{t('common.next')}</button></nav>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
