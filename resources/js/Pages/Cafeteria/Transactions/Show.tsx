import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type Transaction = {
    id: string;
    transaction_number: string;
    employee_id: string;
    cafeteria_provider_id: string;
    transaction_date: string;
    scanned_at: string;
    meal_amount: number;
    subsidy_amount_applied: number;
    employee_payable_amount: number;
    deduction_amount: number;
    transaction_type: string;
    status: string;
    status_color: string;
    scan_sequence_for_day: number;
    is_extra_scan: boolean;
    is_holiday: boolean;
    is_working_day: boolean;
    notes: string | null;
    created_at: string;
    can: { reverse: boolean };
};

export default function TransactionShow({ transaction }: { transaction: Transaction }) {
    const { t } = useLocale();
    const { confirm } = useConfirm();

    async function handleReverse() {
        const { confirmed } = await confirm({
            title: t('cafeteria.reverseTransaction'),
            description: t('cafeteria.alreadyReversed'),
            confirmLabel: t('confirmations.confirm'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) router.post(route('cafeteria.transactions.reverse', transaction.id));
    }

    const statusLabel = (s: string) => ({
        accepted:   t('cafeteria.statusAccepted'),
        reversed:   t('cafeteria.statusReversed'),
        allowed:    t('cafeteria.statusAllowed'),
        denied:     t('cafeteria.statusDenied'),
        extra_scan: t('cafeteria.statusExtraScan'),
    } as Record<string, string>)[s] ?? s;

    const rowCls = 'flex justify-between border-b border-gray-100 py-3 text-sm last:border-0 dark:border-slate-800';
    const labelCls = 'text-gray-500 dark:text-slate-400';
    const valueCls = 'font-medium text-gray-900 dark:text-slate-100';

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('cafeteria.transactions.index')}
                    title={transaction.transaction_number}
                    actions={
                        transaction.can.reverse ? (
                            <button
                                onClick={handleReverse}
                                className="rounded-lg border border-red-300 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:border-red-800 dark:text-red-400"
                            >
                                {t('cafeteria.reverseTransaction')}
                            </button>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={transaction.transaction_number} />

            <div className="mx-auto max-w-2xl space-y-4">
                <div className="rounded-xl border border-gray-200 bg-white px-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className={rowCls}>
                        <span className={labelCls}>{t('cafeteria.transactionStatus')}</span>
                        <StatusBadge status={transaction.status} label={statusLabel(transaction.status)} />
                    </div>
                    <div className={rowCls}>
                        <span className={labelCls}>{t('cafeteria.scanTime')}</span>
                        <span className={valueCls}><LocalizedDateDisplay value={transaction.scanned_at} withTime /></span>
                    </div>
                    <div className={rowCls}>
                        <span className={labelCls}>{t('cafeteria.mealAmount')}</span>
                        <span className={valueCls}>{transaction.meal_amount.toFixed(2)}</span>
                    </div>
                    <div className={rowCls}>
                        <span className={labelCls}>{t('cafeteria.subsidyApplied')}</span>
                        <span className="font-medium text-emerald-600">{transaction.subsidy_amount_applied.toFixed(2)}</span>
                    </div>
                    <div className={rowCls}>
                        <span className={labelCls}>{t('cafeteria.employeePayable')}</span>
                        <span className={valueCls}>{transaction.employee_payable_amount.toFixed(2)}</span>
                    </div>
                    {transaction.is_extra_scan && (
                        <div className={rowCls}>
                            <span className={labelCls}>{t('cafeteria.deductionAmount')}</span>
                            <span className="font-medium text-orange-600">{transaction.deduction_amount.toFixed(2)}</span>
                        </div>
                    )}
                    <div className={rowCls}>
                        <span className={labelCls}>{t('cafeteria.extraScan')}</span>
                        <span className={valueCls}>{transaction.is_extra_scan ? t('common.yes') : t('common.no')}</span>
                    </div>
                    <div className={rowCls}>
                        <span className={labelCls}>{t('cafeteria.isHoliday')}</span>
                        <span className={valueCls}>{transaction.is_holiday ? t('common.yes') : t('common.no')}</span>
                    </div>
                    <div className={rowCls}>
                        <span className={labelCls}>{t('cafeteria.isWorkingDay')}</span>
                        <span className={valueCls}>{transaction.is_working_day ? t('common.yes') : t('common.no')}</span>
                    </div>
                    <div className={rowCls}>
                        <span className={labelCls}>{t('cafeteria.scanSequence')}</span>
                        <span className={valueCls}>{transaction.scan_sequence_for_day}</span>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
