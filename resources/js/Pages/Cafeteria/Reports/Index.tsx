import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

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
};

type Meta = { current_page: number; last_page: number; total: number };

export default function ReportsIndex({
    reports,
    meta,
    filters,
    can,
}: {
    reports: ReportRun[];
    meta: Meta;
    filters: Record<string, string>;
    can: { generate: boolean };
}) {
    const { t } = useLocale();
    const [showForm, setShowForm] = useState(false);
    const form = useForm({
        report_type: 'monthly',
        period_start: '',
        period_end: '',
        organization_id: '',
    });

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

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
                    actions={
                        can.generate ? (
                            <button
                                onClick={() => setShowForm(!showForm)}
                                className="rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                            >
                                {t('cafeteria.generateReport')}
                            </button>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('cafeteria.reports')} />

            <div className="space-y-4">
                {showForm && (
                    <form
                        onSubmit={submit}
                        className="rounded-card border border-blue-200 bg-blue-50 p-5 dark:border-blue-900 dark:bg-blue-950/30"
                    >
                        <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-white">{t('cafeteria.generateReport')}</h3>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <div className="space-y-1">
                                <label className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.reportType')}</label>
                                <select className={inputCls} value={form.data.report_type} onChange={(e) => form.setData('report_type', e.target.value)}>
                                    <option value="daily">{t('cafeteria.daily')}</option>
                                    <option value="monthly">{t('cafeteria.monthly')}</option>
                                </select>
                            </div>
                            <div className="space-y-1">
                                <label className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.periodStart')}</label>
                                <LocalizedDatePicker className={inputCls} value={form.data.period_start} onChange={(iso) => form.setData('period_start', iso)} required />
                            </div>
                            <div className="space-y-1">
                                <label className="text-xs font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.periodEnd')}</label>
                                <LocalizedDatePicker className={inputCls} value={form.data.period_end} onChange={(iso) => form.setData('period_end', iso)} required />
                            </div>
                        </div>
                        <div className="mt-4 flex gap-3">
                            <button type="submit" disabled={form.processing} className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60">
                                {t('cafeteria.generateReport')}
                            </button>
                            <button type="button" onClick={() => setShowForm(false)} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300">
                                {t('common.cancel')}
                            </button>
                        </div>
                    </form>
                )}

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {reports.length === 0 ? (
                        <EmptyState title={t('common.noResults')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.reportNumber')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.reportType')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.periodStart')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.periodEnd')}</th>
                                        <th className="px-4 py-3 text-right font-medium text-gray-600 dark:text-slate-400">{t('cafeteria.totalSubsidy')}</th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">{t('common.generatedAt')}</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {reports.map((rpt) => (
                                        <tr key={rpt.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="px-4 py-3 font-mono text-xs text-gray-700 dark:text-slate-300">{rpt.report_number}</td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400">{reportTypeLabel(rpt.report_type)}</td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400"><LocalizedDateDisplay value={rpt.period_start} /></td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400"><LocalizedDateDisplay value={rpt.period_end} /></td>
                                            <td className="px-4 py-3 text-right font-medium text-emerald-600">{rpt.totals?.total_subsidy?.toFixed(2) ?? '—'}</td>
                                            <td className="px-4 py-3 text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={rpt.generated_at} withTime /></td>
                                            <td className="px-4 py-3 text-right">
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
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
