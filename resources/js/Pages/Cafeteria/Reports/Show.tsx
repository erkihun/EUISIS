import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type ReportRun = {
    id: string;
    report_number: string;
    report_type: string;
    period_start: string;
    period_end: string;
    status: string;
    generated_at: string;
    totals: {
        total_transactions?: number;
        total_extra_scans?: number;
        total_meal_amount?: number;
        total_subsidy?: number;
        total_employee_payable?: number;
        total_deductions?: number;
    } | null;
    can: { export: boolean };
};

export default function ReportShow({ report }: { report: ReportRun }) {
    const { t } = useLocale();

    const reportTypeLabel = (s: string) => ({
        daily:   t('cafeteria.daily'),
        weekly:  t('cafeteria.weekly'),
        monthly: t('cafeteria.monthly'),
    } as Record<string, string>)[s] ?? s;

    const cardCls = 'rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900';
    const labelCls = 'text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400';
    const valueCls = 'mt-1 text-2xl font-bold text-gray-900 dark:text-white';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader backHref={route('cafeteria.reports.index')} title={report.report_number} />
            }
        >
            <Head title={report.report_number} />

            <div className="space-y-6">
                <div className="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <dt className="text-xs text-gray-500">{t('cafeteria.reportType')}</dt>
                            <dd className="mt-1 font-medium text-gray-900 dark:text-white">{reportTypeLabel(report.report_type)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">{t('cafeteria.periodStart')}</dt>
                            <dd className="mt-1 font-medium text-gray-900 dark:text-white"><LocalizedDateDisplay value={report.period_start} /></dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">{t('cafeteria.periodEnd')}</dt>
                            <dd className="mt-1 font-medium text-gray-900 dark:text-white"><LocalizedDateDisplay value={report.period_end} /></dd>
                        </div>
                        <div>
                            <dt className="text-xs text-gray-500">{t('common.generatedAt')}</dt>
                            <dd className="mt-1 font-medium text-gray-900 dark:text-white">
                                <LocalizedDateDisplay value={report.generated_at} withTime />
                            </dd>
                        </div>
                    </dl>
                </div>

                {report.totals && (
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
                        <div className={cardCls}>
                            <p className={labelCls}>{t('cafeteria.totalTransactions')}</p>
                            <p className={valueCls}>{report.totals.total_transactions ?? 0}</p>
                        </div>
                        <div className={cardCls}>
                            <p className={labelCls}>{t('cafeteria.totalExtraScans')}</p>
                            <p className={`${valueCls} text-orange-600`}>{report.totals.total_extra_scans ?? 0}</p>
                        </div>
                        <div className={cardCls}>
                            <p className={labelCls}>{t('cafeteria.totalSubsidy')}</p>
                            <p className={`${valueCls} text-emerald-600`}>{(report.totals.total_subsidy ?? 0).toFixed(2)} ETB</p>
                        </div>
                        <div className={cardCls}>
                            <p className={labelCls}>{t('cafeteria.employeeTotalPayable')}</p>
                            <p className={valueCls}>{(report.totals.total_employee_payable ?? 0).toFixed(2)} ETB</p>
                        </div>
                        <div className={cardCls}>
                            <p className={labelCls}>{t('cafeteria.totalDeductions')}</p>
                            <p className={`${valueCls} text-orange-600`}>{(report.totals.total_deductions ?? 0).toFixed(2)} ETB</p>
                        </div>
                        <div className={cardCls}>
                            <p className={labelCls}>{t('cafeteria.governmentPayable')}</p>
                            <p className={`${valueCls} text-blue-600`}>{(report.totals.total_subsidy ?? 0).toFixed(2)} ETB</p>
                        </div>
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
