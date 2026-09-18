import { Head, Link } from '@inertiajs/react';
import {
    CartesianGrid,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { AppMetricCard } from '@/Components/ui';
import StatusDistribution from '@/Components/dashboard/StatusDistribution';
import { NfcIcon, RouterIcon, ShieldCheck, ScrollText } from '@/Components/Icons';
import { useTerminalTypeLabel } from '@/Components/Nfc/NfcStatusBadge';
import { useChartColors } from '@/hooks/useChartColors';
import { useLocale } from '@/hooks/useLocale';
import NfcSubNav, { type NfcCapabilities } from './Partials/NfcSubNav';

type Props = {
    summary: {
        credentials: number;
        statuses: Record<string, number>;
        activeTerminals: number;
        verificationsToday: number;
        allowedToday: number;
        blockedToday: number;
    };
    statusDistribution: { key: string; value: number }[];
    trend: { day: string; allowed: number; blocked: number }[];
    terminalTypes: { key: string; value: number }[];
    can: NfcCapabilities;
};

export default function NfcDashboard({ summary, statusDistribution, trend, terminalTypes, can }: Props) {
    const { t } = useLocale();
    const { series } = useChartColors();
    const terminalTypeLabel = useTerminalTypeLabel();

    const statusLabel = (key: string) =>
        t(`nfc.status${key.charAt(0).toUpperCase()}${key.slice(1)}`);

    const hasTrend = trend.some((point) => point.allowed > 0 || point.blocked > 0);

    return (
        <AuthenticatedLayout>
            <Head title={t('nfc.management')} />

            <PageHeader title={t('nfc.management')} description={t('nfc.dashboard')} />

            <NfcSubNav can={can} current="dashboard" />

            {/* Headline counts */}
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <AppMetricCard
                    label={t('nfc.totalCredentials')}
                    value={summary.credentials}
                    icon={<NfcIcon className="h-5 w-5" />}
                    variant="primary"
                />
                <AppMetricCard
                    label={t('nfc.activeTerminals')}
                    value={summary.activeTerminals}
                    icon={<RouterIcon className="h-5 w-5" />}
                    variant="accent"
                />
                <AppMetricCard
                    label={t('nfc.successfulToday')}
                    value={summary.allowedToday}
                    detail={`${t('nfc.verificationsToday')}: ${summary.verificationsToday}`}
                    icon={<ShieldCheck className="h-5 w-5" />}
                    variant="success"
                />
                <AppMetricCard
                    label={t('nfc.blockedToday')}
                    value={summary.blockedToday}
                    icon={<ScrollText className="h-5 w-5" />}
                    variant={summary.blockedToday > 0 ? 'danger' : 'neutral'}
                />
            </div>

            {/* Per-status counts */}
            <div className="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">
                {(['pending', 'active', 'suspended', 'lost', 'revoked', 'replaced', 'expired'] as const).map((status) => (
                    <Link
                        key={status}
                        href={can.viewCredentials ? route('nfc-management.credentials.index', { status }) : '#'}
                        className="rounded-card border border-gray-200 bg-white px-3 py-3 transition hover:border-blue-300 dark:border-slate-800 dark:bg-slate-900 dark:hover:border-blue-700"
                    >
                        <p className="truncate text-xs text-gray-500 dark:text-slate-400">{statusLabel(status)}</p>
                        <p className="mt-1 text-lg font-semibold text-gray-900 dark:text-slate-100">
                            {summary.statuses[status] ?? 0}
                        </p>
                    </Link>
                ))}
            </div>

            <div className="mt-6 grid gap-4 lg:grid-cols-2">
                {/* Verification trend */}
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('nfc.verificationTrend')}
                    </h2>
                    {hasTrend ? (
                        <div className="h-64 min-w-0">
                            <ResponsiveContainer width="100%" height={256}>
                                <LineChart data={trend} margin={{ top: 5, right: 8, bottom: 5, left: -20 }}>
                                    <CartesianGrid strokeDasharray="3 3" className="stroke-gray-200 dark:stroke-slate-800" />
                                    <XAxis dataKey="day" tickFormatter={(d: string) => d.slice(5)} fontSize={11} />
                                    <YAxis allowDecimals={false} fontSize={11} />
                                    <Tooltip />
                                    <Legend />
                                    <Line
                                        type="monotone"
                                        dataKey="allowed"
                                        name={t('nfc.resultAllowed')}
                                        stroke={series[0]}
                                        strokeWidth={2}
                                        dot={false}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="blocked"
                                        name={t('nfc.resultBlocked')}
                                        stroke={series[3] ?? series[1]}
                                        strokeWidth={2}
                                        dot={false}
                                    />
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    ) : (
                        <p className="py-10 text-center text-sm text-gray-500 dark:text-slate-400">
                            {t('nfc.noVerificationActivity')}
                        </p>
                    )}
                </section>

                {/* Status distribution */}
                <section className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('nfc.statusDistribution')}
                    </h2>
                    <StatusDistribution data={statusDistribution} labelFor={statusLabel} />
                </section>
            </div>

            {/* Terminals by type */}
            {terminalTypes.length > 0 && (
                <section className="mt-4 rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('nfc.usageByTerminalType')}
                    </h2>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {terminalTypes.map((row) => (
                            <div
                                key={row.key}
                                className="rounded-card border border-gray-100 px-3 py-2 dark:border-slate-800"
                            >
                                <p className="truncate text-xs text-gray-500 dark:text-slate-400">
                                    {terminalTypeLabel(row.key)}
                                </p>
                                <p className="text-base font-semibold text-gray-900 dark:text-slate-100">{row.value}</p>
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </AuthenticatedLayout>
    );
}
