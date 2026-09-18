import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import PortalDatePicker from '@/Components/Calendar/PortalDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Link, router } from '@inertiajs/react';
import { FormEvent, useRef, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

// ── Types ─────────────────────────────────────────────────────────────────────
type Provider = { id: string; code: string; name_en: string; name_am?: string | null };
type Transaction = {
    id: string;
    transaction_number: string;
    transaction_date: string;
    scanned_at: string;
    status: string;
    subsidy_amount_applied: number;
    employee_payable_amount?: number;
    is_extra_scan?: boolean;
    usage_mode?: string;
    employee?: { display_name?: string | null; employee_number?: string | null } | null;
};
type Meta = { current_page: number; last_page: number; total: number };
type PaymentSummary = {
    period_start: string; period_end: string;
    total_transactions: number; accepted_transactions: number; rejected_transactions: number;
    total_subsidy_payable: number; total_employee_payable: number;
    reversal_amount: number; net_payable_amount: number;
    total_food_orders_served?: number;
};
type PageCan = { exportTransactions?: boolean; exportXlsx?: boolean; exportPdf?: boolean };

// ── Helpers ───────────────────────────────────────────────────────────────────
const money = (v: number) => Number(v ?? 0).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

const STATUS_META: Record<string, { cls: string; dot: string; label: string }> = {
    accepted: { cls: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-400 dark:ring-emerald-800', dot: 'bg-emerald-500', label: 'Accepted' },
    reversed: { cls: 'bg-red-50 text-red-700 ring-1 ring-red-200 dark:bg-red-900/20 dark:text-red-400 dark:ring-red-800', dot: 'bg-red-500', label: 'Reversed' },
    rejected: { cls: 'bg-gray-100 text-gray-600 ring-1 ring-gray-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700', dot: 'bg-gray-400', label: 'Rejected' },
    extra_scan: { cls: 'bg-orange-50 text-orange-700 ring-1 ring-orange-200 dark:bg-orange-900/20 dark:text-orange-400 dark:ring-orange-800', dot: 'bg-orange-500', label: 'Extra Scan' },
};

function StatusPill({ status }: { status: string }) {
    const m = STATUS_META[status] ?? STATUS_META.rejected;
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold ${m.cls}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${m.dot}`} />
            {m.label}
        </span>
    );
}

// ── Export dropdown ───────────────────────────────────────────────────────────
function ExportMenu({
    filters, can, onExport,
}: {
    filters: Record<string, string>;
    can: PageCan;
    onExport: (label: string, url: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const { t, locale } = useLocale();

    // Include locale so backend can format dates/labels in the correct language.
    const q = {
        ...Object.fromEntries(
            Object.entries(filters).filter(([, v]) => v !== '' && v !== 'false' && v != null)
        ),
        locale,
    };

    const btn = (label: string, routeName: string, variant: 'primary' | 'secondary' = 'secondary') => (
        <button
            type="button"
            onClick={() => { onExport(label, route(routeName, q)); setOpen(false); }}
            className={[
                'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-xs font-medium transition-colors',
                variant === 'primary'
                    ? 'bg-orange-500/8 text-orange-700 hover:bg-orange-500/15 dark:text-orange-400'
                    : 'text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800',
            ].join(' ')}
        >
            {label}
        </button>
    );

    return (
        <div className="relative" ref={ref}>
            <button
                type="button"
                onClick={() => setOpen(o => !o)}
                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
            >
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                </svg>
                {t('providerPortal.exportTransactions')}
                <svg className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                </svg>
            </button>

            {open && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                    <div className="absolute right-0 z-20 mt-1.5 w-64 rounded-card border border-gray-200 bg-white p-1.5 shadow-xl dark:border-slate-700 dark:bg-slate-900">

                        {/* Transaction exports */}
                        <p className="px-3 pb-1 pt-1.5 text-[10px] font-bold text-gray-400 dark:text-slate-600">
                            {t('providerPortal.transactions')}
                        </p>
                        {btn(`CSV — ${t('providerPortal.transactions')}`, 'provider.portal.transactions.export.csv')}
                        {can.exportXlsx && btn(`XLSX — ${t('providerPortal.transactions')}`, 'provider.portal.transactions.export.xlsx')}
                        {can.exportPdf && btn(`PDF — ${t('providerPortal.transactions')}`, 'provider.portal.transactions.export.pdf')}

                        <div className="my-1.5 h-px bg-gray-100 dark:bg-slate-800" />

                        {/* Payment claim exports */}
                        <p className="px-3 pb-1 text-[10px] font-bold text-gray-400 dark:text-slate-600">
                            {t('providerPortal.paymentClaim')}
                        </p>
                        {btn(`${t('providerPortal.paymentClaim')} — CSV`, 'provider.portal.transactions.export.payment-claim', 'primary')}
                        {can.exportXlsx && btn(`${t('providerPortal.paymentClaim')} — XLSX`, 'provider.portal.transactions.export.payment-claim.xlsx', 'primary')}
                        {can.exportPdf && btn(`${t('providerPortal.paymentClaim')} — PDF`, 'provider.portal.transactions.export.payment-claim.pdf', 'primary')}
                    </div>
                </>
            )}
        </div>
    );
}

// ── KPI summary strip ─────────────────────────────────────────────────────────
function SummaryStrip({ summary }: { summary: PaymentSummary }) {
    const cards = [
        {
            label: 'Total Scans', value: String(summary.total_transactions),
            sub: `${summary.accepted_transactions} accepted`, icon: '⚡',
            cls: 'bg-violet-50 dark:bg-violet-950/20', icon_cls: 'bg-violet-100 dark:bg-violet-900/30',
        },
        {
            label: 'Subsidy Payable', value: money(summary.total_subsidy_payable),
            sub: summary.reversal_amount > 0 ? `−${money(summary.reversal_amount)} reversed` : 'No reversals',
            icon: '💰', cls: 'bg-emerald-50 dark:bg-emerald-950/20', icon_cls: 'bg-emerald-100 dark:bg-emerald-900/30',
        },
        {
            label: 'Employee Payable', value: money(summary.total_employee_payable),
            sub: 'Collected from employees', icon: '👤',
            cls: 'bg-blue-50 dark:bg-blue-950/20', icon_cls: 'bg-blue-100 dark:bg-blue-900/30',
        },
        {
            label: 'Net Payable', value: money(summary.net_payable_amount),
            sub: `${summary.period_start} → ${summary.period_end}`, icon: '🧾',
            cls: 'bg-orange-50 dark:bg-orange-950/20', icon_cls: 'bg-orange-100 dark:bg-orange-900/30',
        },
    ];

    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {cards.map(c => (
                <div key={c.label} className={`flex items-start gap-3.5 rounded-card border border-gray-200 p-4 dark:border-slate-800 ${c.cls}`}>
                    <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-card text-xl ${c.icon_cls}`}>{c.icon}</span>
                    <div className="min-w-0">
                        <p className="text-[11px] font-semibold text-gray-500 dark:text-slate-500">{c.label}</p>
                        <p className="mt-0.5 text-xl font-bold tabular-nums text-gray-900 dark:text-slate-100">{c.value}</p>
                        <p className="mt-0.5 truncate text-[11px] text-gray-500 dark:text-slate-500">{c.sub}</p>
                    </div>
                </div>
            ))}
        </div>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────
const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400/20';

export default function TransactionsIndex({
    providers, selected_provider_id, transactions, meta, filters, paymentSummary, can,
}: {
    providers: Provider[];
    selected_provider_id: string | null;
    transactions: Transaction[];
    meta: Meta;
    filters: Record<string, string>;
    paymentSummary: PaymentSummary;
    can: PageCan;
}) {
    const { t } = useLocale();
    const [exportToast, setExportToast] = useState<string | null>(null);
    const [startDate, setStartDate] = useState(filters.start_date ?? '');
    const [endDate, setEndDate] = useState(filters.end_date ?? '');

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget);
        const params = Object.fromEntries(fd) as Record<string, string>;
        params.start_date = startDate;
        params.end_date = endDate;
        router.get(route('provider.portal.transactions.index'), params, { preserveState: true });
    }

    function handleExport(label: string, url: string) {
        window.location.href = url;
        setExportToast(label);
        setTimeout(() => setExportToast(null), 3000);
    }

    return (
        <CafeteriaProviderPortalLayout
            title={t('providerPortal.transactions')}
            header={
                <PageHeader
                    title={t('providerPortal.transactions')}
                    description={`${paymentSummary.period_start} — ${paymentSummary.period_end}`}
                    actions={
                        can.exportTransactions
                            ? <ExportMenu filters={filters} can={can} onExport={handleExport} />
                            : undefined
                    }
                />
            }
            providers={providers}
            selectedProviderId={selected_provider_id}
        >

            {/* Export toast */}
            {exportToast && (
                <div className="fixed left-1/2 top-5 z-50 -translate-x-1/2 flex items-center gap-2 rounded-card bg-gray-900 px-4 py-2.5 text-sm font-medium text-white shadow-xl dark:bg-slate-700">
                    <svg className="h-4 w-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    Downloading: {exportToast}
                </div>
            )}

            <div className="space-y-5">
                {/* KPI strip */}
                <SummaryStrip summary={paymentSummary} />

                {/* Filter bar */}
                <div className="rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-3 p-4">
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">From</label>
                            <PortalDatePicker value={startDate} onChange={setStartDate} className={inputCls} />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">To</label>
                            <PortalDatePicker value={endDate} onChange={setEndDate} className={inputCls} />
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">Status</label>
                            <select name="status" defaultValue={filters.status ?? ''} className={inputCls}>
                                <option value="">All Statuses</option>
                                <option value="accepted">Accepted</option>
                                <option value="reversed">Reversed</option>
                                <option value="rejected">Rejected</option>
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-xs font-medium text-gray-500 dark:text-slate-400">Employee</label>
                            <input name="employee_search" type="text" placeholder="Search name / ID…"
                                defaultValue={filters.employee_search ?? ''} className={inputCls} />
                        </div>
                        <button type="submit"
                            className="rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-600">
                            Apply
                        </button>
                        {Object.values(filters).some(v => v && v !== 'false') && (
                            <button type="button"
                                onClick={() => router.get(route('provider.portal.transactions.index'), {})}
                                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-400">
                                Clear
                            </button>
                        )}
                    </form>
                </div>

                {/* Transactions table */}
                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {/* Table toolbar */}
                    <div className="flex items-center justify-between border-b border-gray-100 px-5 py-3.5 dark:border-slate-800">
                        <div>
                            <span className="text-sm font-semibold text-gray-900 dark:text-slate-100">{meta.total.toLocaleString()}</span>
                            <span className="ml-1.5 text-sm text-gray-500 dark:text-slate-400">transactions</span>
                        </div>
                        <div className="flex items-center gap-2 text-xs text-gray-400 dark:text-slate-500">
                            <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Export applies current filters
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-gray-100 bg-gray-50/80 dark:border-slate-800 dark:bg-slate-800/40">
                                    <th className="px-5 py-3 text-left text-[11px] font-semibold text-gray-500 dark:text-slate-500">Txn #</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-gray-500 dark:text-slate-500">Employee</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-gray-500 dark:text-slate-500">Date & Time</th>
                                    <th className="px-4 py-3 text-right text-[11px] font-semibold text-gray-500 dark:text-slate-500">Subsidy</th>
                                    <th className="px-4 py-3 text-right text-[11px] font-semibold text-gray-500 dark:text-slate-500">Emp. Pays</th>
                                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-gray-500 dark:text-slate-500">Status</th>
                                    <th className="px-4 py-3 w-16" />
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100/80 dark:divide-slate-800">
                                {transactions.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-5 py-16 text-center">
                                            <div className="flex flex-col items-center gap-2">
                                                <span className="text-3xl">📋</span>
                                                <p className="text-sm font-medium text-gray-500 dark:text-slate-400">No transactions found</p>
                                                <p className="text-xs text-gray-400 dark:text-slate-500">Try adjusting your filters</p>
                                            </div>
                                        </td>
                                    </tr>
                                ) : transactions.map(tx => (
                                    <tr key={tx.id} className="group transition-colors hover:bg-orange-50/40 dark:hover:bg-orange-950/10">
                                        <td className="px-5 py-3.5">
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono text-[11px] font-medium text-gray-700 dark:text-slate-300">{tx.transaction_number}</span>
                                                {tx.is_extra_scan && (
                                                    <span className="rounded-full bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold text-orange-600 dark:bg-orange-900/30 dark:text-orange-400">EXTRA</span>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <div>
                                                <p className="font-medium text-gray-900 dark:text-slate-100">{tx.employee?.display_name ?? '—'}</p>
                                                {tx.employee?.employee_number && (
                                                    <p className="font-mono text-[11px] text-gray-400 dark:text-slate-500">#{tx.employee.employee_number}</p>
                                                )}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3.5">
                                            {tx.scanned_at ? (
                                                <div>
                                                    <LocalizedDateDisplay value={tx.scanned_at} className="text-[13px] text-gray-700 dark:text-slate-300" />
                                                    <p className="text-[11px] text-gray-400 dark:text-slate-500">
                                                        {new Date(tx.scanned_at).toLocaleTimeString('en', { hour: '2-digit', minute: '2-digit' })}
                                                    </p>
                                                </div>
                                            ) : '—'}
                                        </td>
                                        <td className="px-4 py-3.5 text-right">
                                            <span className="text-[13px] font-bold text-emerald-600 dark:text-emerald-400">
                                                {tx.subsidy_amount_applied > 0 ? money(tx.subsidy_amount_applied) : <span className="font-normal text-gray-400">—</span>}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5 text-right">
                                            <span className="text-[13px] text-gray-700 dark:text-slate-300">
                                                {(tx.employee_payable_amount ?? 0) > 0 ? money(tx.employee_payable_amount!) : <span className="text-gray-400">—</span>}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3.5">
                                            <StatusPill status={tx.status} />
                                        </td>
                                        <td className="px-4 py-3.5 text-right">
                                            <Link
                                                href={route('provider.portal.transactions.show', tx.id)}
                                                className="rounded-lg border border-transparent px-2.5 py-1 text-[11px] font-semibold text-orange-600 transition hover:border-orange-200 hover:bg-orange-50 dark:text-orange-400 dark:hover:border-orange-900 dark:hover:bg-orange-950/30"
                                            >
                                                View →
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {meta.last_page > 1 && (
                        <div className="flex items-center justify-between border-t border-gray-100 px-5 py-3 dark:border-slate-800">
                            <p className="text-xs text-gray-500 dark:text-slate-400">
                                Page {meta.current_page} of {meta.last_page}
                            </p>
                            <div className="flex items-center gap-1">
                                {Array.from({ length: Math.min(meta.last_page, 10) }, (_, i) => i + 1).map(p => (
                                    <button
                                        key={p}
                                        onClick={() => router.get(route('provider.portal.transactions.index'), { ...filters, page: String(p) })}
                                        className={[
                                            'min-w-[32px] rounded-lg px-2.5 py-1.5 text-xs font-medium transition',
                                            meta.current_page === p
                                                ? 'bg-orange-500 text-white shadow-sm'
                                                : 'text-gray-600 hover:bg-gray-100 dark:text-slate-400 dark:hover:bg-slate-800',
                                        ].join(' ')}
                                    >
                                        {p}
                                    </button>
                                ))}
                                {meta.last_page > 10 && <span className="px-1 text-xs text-gray-400">…{meta.last_page}</span>}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
