import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import EmptyState from '@/Components/EmptyState';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useConfirm } from '@/hooks/useConfirm';

type ProviderUser = {
    id: string;
    name: string;
    email: string | null;
    username: string | null;
    status: string;
    portal_enabled: boolean;
    service_type: { id: string; code: string; name_en: string } | null;
    provider: { id: string; code: string; name: string; status: string } | null;
    can: {
        view: boolean;
        edit: boolean;
        delete: boolean;
        suspend: boolean;
        resetPassword: boolean;
    };
};

type Meta = { current_page: number; last_page: number; total: number };

const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';
const thCls = 'px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-slate-400';

export default function AllProviderUsersIndex({
    providerUsers,
    meta,
    filters,
}: {
    providerUsers: { data: ProviderUser[] };
    meta: Meta;
    filters: { search?: string; status?: string };
}) {
    const { confirm } = useConfirm();

    function submit(e: FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const data = new FormData(e.currentTarget);
        router.get(route('provider-users.index'), {
            search: data.get('search') as string,
            status: data.get('status') as string,
        }, { preserveScroll: true });
    }

    async function handleDelete(user: ProviderUser) {
        const { confirmed } = await confirm({
            title: 'Delete Provider User',
            description: `Delete "${user.name}"? This cannot be undone.`,
            confirmLabel: 'Delete',
            cancelLabel: 'Cancel',
            variant: 'danger',
        });
        if (confirmed) {
            router.delete(route('provider-users.destroy', user.id), { preserveScroll: true });
        }
    }

    async function handleSuspend(user: ProviderUser) {
        const { confirmed } = await confirm({
            title: 'Suspend Provider User',
            description: `Suspend "${user.name}"?`,
            confirmLabel: 'Suspend',
            cancelLabel: 'Cancel',
            variant: 'danger',
        });
        if (confirmed) {
            router.post(route('provider-users.suspend', user.id), {}, { preserveScroll: true });
        }
    }

    function handleActivate(user: ProviderUser) {
        router.post(route('provider-users.activate', user.id), {}, { preserveScroll: true });
    }

    const statusOptions = [
        { value: '', label: 'All Statuses' },
        { value: 'active', label: 'Active' },
        { value: 'inactive', label: 'Inactive' },
        { value: 'suspended', label: 'Suspended' },
    ];

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title="Provider Users"
                    actions={
                        <Link
                            href={route('provider-users.create')}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-[color:var(--color-primary)] px-3 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]"
                        >
                            + Create Provider User
                        </Link>
                    }
                />
            }
        >
            <Head title="Provider Users" />

            <div className="space-y-4">
                {/* Filters */}
                <form className="flex flex-wrap gap-3" onSubmit={submit}>
                    <input
                        name="search"
                        defaultValue={filters.search ?? ''}
                        placeholder="Search name, email, username…"
                        className={inputCls}
                    />
                    <select name="status" defaultValue={filters.status ?? ''} className={inputCls}>
                        {statusOptions.map(o => (
                            <option key={o.value} value={o.value}>{o.label}</option>
                        ))}
                    </select>
                    <button type="submit" className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]">
                        Filter
                    </button>
                    {(filters.search || filters.status) && (
                        <button
                            type="button"
                            onClick={() => router.get(route('provider-users.index'))}
                            className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            Clear
                        </button>
                    )}
                </form>

                {providerUsers.data.length === 0 ? (
                    <EmptyState title="No provider users found" />
                ) : (
                    <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-gray-200 dark:divide-slate-800">
                                <thead className="bg-gray-50 dark:bg-slate-800/60">
                                    <tr>
                                        <th className={thCls}>Name</th>
                                        <th className={thCls}>Email / Username</th>
                                        <th className={thCls}>Service Type</th>
                                        <th className={thCls}>Provider</th>
                                        <th className={thCls}>Status</th>
                                        <th className={thCls}>Portal</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {providerUsers.data.map(pu => (
                                        <tr key={pu.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="px-4 py-3 text-sm font-medium text-gray-900 dark:text-slate-100">
                                                {pu.name}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-500 dark:text-slate-400">
                                                {pu.email && <div>{pu.email}</div>}
                                                {pu.username && <div className="font-mono text-xs">{pu.username}</div>}
                                                {!pu.email && !pu.username && '—'}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-500 dark:text-slate-400">
                                                {pu.service_type
                                                    ? <><span className="font-medium text-gray-700 dark:text-slate-300">{pu.service_type.name_en}</span><br /><span className="font-mono text-xs">{pu.service_type.code}</span></>
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-500 dark:text-slate-400">
                                                {pu.provider
                                                    ? <><span className="font-medium text-gray-700 dark:text-slate-300">{pu.provider.name}</span><br /><span className="font-mono text-xs">{pu.provider.code}</span></>
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge status={pu.status} />
                                            </td>
                                            <td className="px-4 py-3 text-sm">
                                                <span className={pu.portal_enabled ? 'font-medium text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-slate-500'}>
                                                    {pu.portal_enabled ? 'Enabled' : 'Disabled'}
                                                </span>
                                            </td>

                                            {/* ── Action buttons ── */}
                                            <td className="px-4 py-3 text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    {pu.can.view && (
                                                        <Link
                                                            href={route('provider-users.show', pu.id)}
                                                            className="rounded px-2 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-slate-100"
                                                        >
                                                            View
                                                        </Link>
                                                    )}
                                                    {pu.can.edit && (
                                                        <Link
                                                            href={route('provider-users.edit', pu.id)}
                                                            className="rounded px-2 py-1 text-xs font-medium text-[color:var(--color-primary)] hover:bg-blue-50 hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:bg-blue-900/30"
                                                        >
                                                            Edit
                                                        </Link>
                                                    )}
                                                    {pu.can.suspend && pu.status !== 'suspended' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleSuspend(pu)}
                                                            className="rounded px-2 py-1 text-xs font-medium text-amber-600 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-900/30"
                                                        >
                                                            Suspend
                                                        </button>
                                                    )}
                                                    {pu.can.suspend && pu.status === 'suspended' && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleActivate(pu)}
                                                            className="rounded px-2 py-1 text-xs font-medium text-green-600 hover:bg-green-50 dark:text-green-400 dark:hover:bg-green-900/30"
                                                        >
                                                            Activate
                                                        </button>
                                                    )}
                                                    {pu.can.resetPassword && (
                                                        <Link
                                                            href={route('provider-users.edit', pu.id) + '#reset-password'}
                                                            className="rounded px-2 py-1 text-xs font-medium text-purple-600 hover:bg-purple-50 dark:text-purple-400 dark:hover:bg-purple-900/30"
                                                        >
                                                            Reset PW
                                                        </Link>
                                                    )}
                                                    {pu.can.delete && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleDelete(pu)}
                                                            className="rounded px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30"
                                                        >
                                                            Delete
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        {/* Pagination */}
                        {meta.last_page > 1 && (
                            <div className="flex items-center justify-between border-t border-gray-200 px-4 py-3 dark:border-slate-800">
                                <p className="text-sm text-gray-500 dark:text-slate-400">
                                    Page {meta.current_page} / {meta.last_page} · {meta.total} total
                                </p>
                                <div className="flex gap-2">
                                    {meta.current_page > 1 && (
                                        <button
                                            type="button"
                                            onClick={() => router.get(route('provider-users.index'), { ...filters, page: meta.current_page - 1 })}
                                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800"
                                        >
                                            Prev
                                        </button>
                                    )}
                                    {meta.current_page < meta.last_page && (
                                        <button
                                            type="button"
                                            onClick={() => router.get(route('provider-users.index'), { ...filters, page: meta.current_page + 1 })}
                                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-800"
                                        >
                                            Next
                                        </button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
