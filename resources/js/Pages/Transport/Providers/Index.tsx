import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import EmptyState from '@/Components/EmptyState';
import TransportProviderStatusBadge from '@/Components/transport/TransportProviderStatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Provider = {
    id: string;
    provider_code: string;
    name_en: string;
    status: string;
    contact_person: string | null;
    email: string | null;
    assigned_scope_type: string;
    assigned_organization?: { name_en?: string | null } | null;
};

type Meta = { current_page: number; last_page: number; total: number; per_page: number };

export default function Index({
    providers,
    filters = {},
    can = { create: false, update: false },
}: {
    providers: { data: Provider[]; meta: Meta };
    filters?: Record<string, string>;
    can?: { create: boolean; update: boolean };
}) {
    const { t } = useLocale();
    const rows = providers?.data ?? [];
    const meta = providers?.meta;

    const inputCls =
        'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function submit(e: FormEvent) {
        e.preventDefault();
        const fd = new FormData(e.currentTarget as HTMLFormElement);
        router.get(route('transport.providers.index'), Object.fromEntries(fd) as Record<string, string>, {
            preserveState: true,
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('transport.providers')}
                    actions={
                        can.create ? (
                            <Link
                                href={route('transport.providers.create')}
                                className="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700"
                            >
                                {t('transport.registerProvider')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={t('transport.providers')} />

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
                        <option value="suspended">{t('common.suspended')}</option>
                    </select>
                    <button
                        type="submit"
                        className="rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"
                    >
                        {t('common.filter')}
                    </button>
                </form>

                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    {rows.length === 0 ? (
                        <EmptyState title={t('transport.noRecords')} />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100 bg-gray-50 dark:border-slate-800 dark:bg-slate-800/50">
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.providerCode')}
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.nameEn')}
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.contactPerson')}
                                        </th>
                                        <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-slate-400">
                                            {t('transport.organization')}
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
                                                {item.provider_code}
                                            </td>
                                            <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">
                                                {item.name_en}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                                                {item.contact_person ?? '—'}
                                            </td>
                                            <td className="px-4 py-3 text-gray-600 dark:text-slate-400">
                                                {item.assigned_scope_type === 'citywide' ? t('transport.allOrganizations') : item.assigned_organization?.name_en ?? '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <TransportProviderStatusBadge status={item.status} />
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Link
                                                    href={route('transport.providers.show', item.id)}
                                                    className="text-xs text-blue-600 hover:underline"
                                                >
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

                {meta && meta.last_page > 1 && (
                    <div className="flex justify-center gap-2 text-sm">
                        {Array.from({ length: meta.last_page }, (_, i) => i + 1).map((page) => (
                            <button
                                key={page}
                                onClick={() =>
                                    router.get(route('transport.providers.index'), { ...filters, page })
                                }
                                className={`rounded px-3 py-1 ${
                                    meta.current_page === page
                                        ? 'bg-blue-600 text-white'
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
