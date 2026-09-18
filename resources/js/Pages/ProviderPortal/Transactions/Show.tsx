import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

// ── Types ─────────────────────────────────────────────────────────────────────
type Provider = { id: string; code: string; name_en: string; name_am?: string | null };
type Transaction = {
    transaction_number: string;
    transaction_date?: string;
    scanned_at: string;
    status: string;
    subsidy_amount_applied: number;
    employee_payable_amount: number;
    is_extra_scan?: boolean;
    is_holiday?: boolean;
    is_working_day?: boolean;
    usage_mode?: string;
    consumed_days_count?: number;
    employee?: {
        display_name?: string | null;
        employee_number?: string | null;
        photo_url?: string | null;
        organization?: string | null;
        position?: string | null;
    } | null;
    provider?: { name_en?: string | null; code?: string | null } | null;
    operator?: { name?: string | null } | null;
};

// ── Helpers ───────────────────────────────────────────────────────────────────
const money = (v: number) => Number(v ?? 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const STATUS_CFG: Record<string, { label: string; icon: string; cls: string; glow: string }> = {
    accepted: {
        label: 'Accepted', icon: '✓',
        cls: 'bg-emerald-500 text-white',
        glow: 'shadow-lg shadow-emerald-500/20',
    },
    reversed: {
        label: 'Reversed', icon: '↩',
        cls: 'bg-red-500 text-white',
        glow: 'shadow-lg shadow-red-500/20',
    },
    rejected: {
        label: 'Rejected', icon: '✕',
        cls: 'bg-gray-500 text-white',
        glow: 'shadow-lg shadow-gray-500/20',
    },
};

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-gray-100 py-3 text-sm last:border-0 dark:border-slate-800">
            <span className="w-40 shrink-0 text-gray-500 dark:text-slate-400">{label}</span>
            <span className="min-w-0 text-right font-medium text-gray-900 dark:text-slate-100">{children}</span>
        </div>
    );
}

// ── Component ─────────────────────────────────────────────────────────────────
export default function TransactionShow({
    providers, selected_provider_id, transaction,
}: {
    providers: Provider[];
    selected_provider_id: string | null;
    transaction: Transaction;
}) {
    const { t } = useLocale();
    const cfg = STATUS_CFG[transaction.status] ?? STATUS_CFG.rejected;

    return (
        <CafeteriaProviderPortalLayout
            title={transaction.transaction_number}
            header={
                <PageHeader
                    title={transaction.transaction_number}
                    description="Transaction Detail"
                    backHref={route('provider.portal.transactions.index')}
                />
            }
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <Head title={transaction.transaction_number} />

            <div className="mx-auto max-w-3xl space-y-5">

                {/* ── Hero status banner ── */}
                <div className={`flex items-center justify-between rounded-panel p-5 ${cfg.cls} ${cfg.glow}`}>
                    <div>
                        <p className="text-xs font-semibold text-white/70">Transaction Status</p>
                        <div className="mt-1 flex items-center gap-2">
                            <span className="text-2xl font-bold text-white">{cfg.label}</span>
                            {transaction.is_extra_scan && (
                                <span className="rounded-full bg-white/20 px-2 py-0.5 text-xs font-semibold text-white">EXTRA SCAN</span>
                            )}
                        </div>
                        <p className="mt-1 font-mono text-sm text-white/80">{transaction.transaction_number}</p>
                    </div>
                    <div className="flex h-16 w-16 items-center justify-center rounded-panel bg-white/20 text-3xl font-bold text-white">
                        {cfg.icon}
                    </div>
                </div>

                <div className="grid gap-5 lg:grid-cols-3">

                    {/* ── Employee card ── */}
                    <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-gray-100 px-5 py-3.5 dark:border-slate-800">
                            <p className="text-[11px] font-bold text-gray-400 dark:text-slate-600">Employee</p>
                        </div>
                        <div className="flex flex-col items-center px-5 py-6 text-center">
                            {transaction.employee?.photo_url ? (
                                <img
                                    src={transaction.employee.photo_url}
                                    alt={transaction.employee.display_name ?? ''}
                                    className="h-16 w-16 rounded-full object-cover ring-3 ring-orange-200 dark:ring-orange-900/60"
                                />
                            ) : (
                                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-gradient-to-br from-orange-400 to-orange-600 text-xl font-bold text-white shadow-md">
                                    {(transaction.employee?.display_name ?? 'E').charAt(0).toUpperCase()}
                                </div>
                            )}
                            <p className="mt-3 font-semibold text-gray-900 dark:text-slate-100">
                                {transaction.employee?.display_name ?? '—'}
                            </p>
                            {transaction.employee?.employee_number && (
                                <p className="font-mono text-xs text-gray-400 dark:text-slate-500">
                                    #{transaction.employee.employee_number}
                                </p>
                            )}
                            {transaction.employee?.organization && (
                                <p className="mt-2 text-center text-xs text-gray-500 dark:text-slate-400 leading-relaxed">
                                    {transaction.employee.organization}
                                </p>
                            )}
                            {transaction.employee?.position && (
                                <p className="mt-1 text-center text-xs text-gray-400 dark:text-slate-500">
                                    {transaction.employee.position}
                                </p>
                            )}
                        </div>
                    </div>

                    {/* ── Financial summary ── */}
                    <div className="lg:col-span-2 space-y-3">

                        {/* Amounts */}
                        <div className="grid grid-cols-2 gap-3">
                            <div className="rounded-card border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800/40 dark:bg-emerald-950/20">
                                <p className="text-[11px] font-semibold text-emerald-600 dark:text-emerald-500">Subsidy Applied</p>
                                <p className="mt-1 text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-400">
                                    {money(transaction.subsidy_amount_applied)}
                                </p>
                                <p className="mt-0.5 text-[11px] text-emerald-600/70 dark:text-emerald-500/70">ETB</p>
                            </div>
                            <div className="rounded-card border border-orange-200 bg-orange-50 p-4 dark:border-orange-800/40 dark:bg-orange-950/20">
                                <p className="text-[11px] font-semibold text-orange-600 dark:text-orange-500">Employee Pays</p>
                                <p className="mt-1 text-2xl font-bold tabular-nums text-orange-700 dark:text-orange-400">
                                    {money(transaction.employee_payable_amount)}
                                </p>
                                <p className="mt-0.5 text-[11px] text-orange-600/70 dark:text-orange-500/70">ETB</p>
                            </div>
                        </div>

                        {/* Details */}
                        <div className="rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                            <div className="border-b border-gray-100 px-5 py-3.5 dark:border-slate-800">
                                <p className="text-[11px] font-bold text-gray-400 dark:text-slate-600">Details</p>
                            </div>
                            <div className="px-5">
                                <Row label="Scan Time">
                                    {transaction.scanned_at ? (
                                        <span>
                                            <LocalizedDateDisplay value={transaction.scanned_at} withTime />
                                        </span>
                                    ) : '—'}
                                </Row>
                                {transaction.usage_mode && (
                                    <Row label="Usage Mode">
                                        <span className="capitalize">{transaction.usage_mode.replace(/_/g, ' ')}</span>
                                    </Row>
                                )}
                                {transaction.consumed_days_count !== undefined && (
                                    <Row label="Consumed Days">
                                        <span>{transaction.consumed_days_count} day{transaction.consumed_days_count !== 1 ? 's' : ''}</span>
                                    </Row>
                                )}
                                {transaction.is_working_day !== undefined && (
                                    <Row label="Working Day">
                                        {transaction.is_working_day ? (
                                            <span className="text-emerald-600 dark:text-emerald-400">Yes</span>
                                        ) : (
                                            <span className="text-orange-600 dark:text-orange-400">No</span>
                                        )}
                                    </Row>
                                )}
                                {transaction.provider?.name_en && (
                                    <Row label="Provider">
                                        <div>
                                            <span>{transaction.provider.name_en}</span>
                                            {transaction.provider.code && (
                                                <span className="ml-1.5 font-mono text-xs text-gray-400">({transaction.provider.code})</span>
                                            )}
                                        </div>
                                    </Row>
                                )}
                                {transaction.operator?.name && (
                                    <Row label="Scanned By">{transaction.operator.name}</Row>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
