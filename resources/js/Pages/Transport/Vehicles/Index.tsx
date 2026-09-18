import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Vehicle = {
    id: string;
    vehicle_code: string;
    plate_number: string;
    vehicle_type: string | null;
    capacity: number | null;
    status: string;
    model: string | null;
    year: number | null;
    route: { name_en: string; route_code: string } | null;
};

type Meta = { current_page: number; last_page: number; total: number; per_page: number };

const statusColors: Record<string, string> = {
    active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    inactive: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
    maintenance: 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
};

export default function Index({
    vehicles,
    filters = {},
}: {
    vehicles: { data: Vehicle[]; meta: Meta };
    filters?: Record<string, string>;
}) {
    const { t } = useLocale();
    const rows = vehicles?.data ?? [];
    const meta = vehicles?.meta;

    const inputCls =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        router.get(route('transport.vehicles.index'), Object.fromEntries(fd) as Record<string, string>, {
            preserveState: true,
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('transport.vehicles')}
                    actions={
                        <Link
                            href={route('transport.vehicles.create')}
                            className="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                        >
                            {t('transport.newVehicle')}
                        </Link>
                    }
                />
            }
        >
            <Head title={t('transport.vehicles')} />

            <div className="space-y-4">
                <form className="flex flex-wrap gap-3" onSubmit={submit}>
                    <input
                        name="search"
                        defaultValue={filters.search ?? ''}
                        placeholder={t('common.search')}
                        className={inputCls}
                    />
                    <select name="status" defaultValue={filters.status ?? ''} className={inputCls}>
                        <option value="">
                            {t('transport.status')} — {t('common.all')}
                        </option>
                        <option value="active">{t('transport.active')}</option>
                        <option value="inactive">{t('transport.inactive')}</option>
                    </select>
                    <button
                        type="submit"
                        className="rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                    >
                        {t('common.filter')}
                    </button>
                </form>

                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    {rows.length === 0 ? (
                        <EmptyState title={t('transport.noRecords')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.vehicleCode')}
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.plateNumber')}
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.vehicleType')}
                                        </th>
                                        <th className="px-4 py-3 text-center font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.capacity')}
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.assignedRoute')}
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.status')}
                                        </th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {rows.map((item) => (
                                        <tr key={item.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="px-4 py-3 font-mono text-xs text-gray-700 dark:text-slate-300">
                                                {item.vehicle_code}
                                            </td>
                                            <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">
                                                {item.plate_number}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                                                {item.vehicle_type ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-center text-gray-600 dark:text-slate-400">
                                                {item.capacity ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                                                {item.route
                                                    ? `${item.route.route_code} · ${item.route.name_en}`
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${statusColors[item.status] ?? statusColors.inactive}`}
                                                >
                                                    {item.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    href={route('transport.vehicles.edit', item.id)}
                                                    className="text-xs text-[color:var(--color-primary)] hover:underline"
                                                >
                                                    {t('common.edit')}
                                                </Link>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {meta && meta.last_page > 1 && (
                    <div className="flex justify-center gap-2 text-sm">
                        {Array.from({ length: meta.last_page }, (_, i) => i + 1).map((page) => (
                            <button
                                key={page}
                                onClick={() =>
                                    router.get(route('transport.vehicles.index'), { ...filters, page })
                                }
                                className={`rounded px-3 py-1 ${
                                    meta.current_page === page
                                        ? 'bg-[color:var(--color-primary)] text-white'
                                        : 'border border-gray-300 text-gray-600 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-400'
                                }`}
                            >
                                {page}
                            </button>
                        ))}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
