import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

type Provider = {
    id: string; code: string; name_en: string; name_am: string | null;
    contact_person: string | null; phone_number: string | null; email: string | null;
    location: string | null; is_active: boolean; created_at: string;
    can: { update: boolean; archive: boolean };
};

type Branch = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    location: string | null;
    contact_person: string | null;
    phone_number: string | null;
    is_active: boolean;
    organization: { name_en: string; code: string } | null;
};

type AdminUser = {
    id: string;
    provider_role: string | null;
    is_active: boolean;
    effective_from: string | null;
    effective_to: string | null;
    user: { name: string | null; email: string | null; status: string | null };
    branch: { code: string; name_en: string } | null;
    organization: { name_en: string; code: string } | null;
};

export default function ProviderShow({
    provider,
    branches = [],
    adminUsers = [],
    can,
}: {
    provider: Provider;
    branches?: Branch[];
    adminUsers?: AdminUser[];
    can: { update: boolean; manageUsers: boolean };
}) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const rowCls = 'flex justify-between border-b border-gray-100 py-3 text-sm last:border-0 dark:border-slate-800';
    const thCls = 'px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-slate-400';
    const tdCls = 'px-4 py-3 text-sm text-gray-700 dark:text-slate-300';
    const cardCls = 'rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900';

    async function archiveBranch(branchId: string) {
        const { confirmed } = await confirm({
            title: t('confirmations.confirmDeleteTitle'),
            description: t('confirmations.thisRecordWillMoveToRecycleBin'),
            confirmLabel: t('confirmations.delete'),
            cancelLabel: t('confirmations.cancel'),
            variant: 'danger',
        });
        if (confirmed) {
            router.delete(route('cafeteria.providers.branches.archive', {
                cafeteriaProvider: provider.id,
                branch: branchId,
            }), { preserveScroll: true });
        }
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('cafeteria.providers.index')}
                    title={provider.name_en}
                    actions={
                        can.update ? (
                            <Link
                                href={route('cafeteria.providers.edit', provider.id)}
                                className="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {t('common.edit')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <div className="mx-auto max-w-3xl space-y-5">

                {/* Provider details */}
                <div className={`${cardCls} px-6`}>
                    <div className={rowCls}><span className="text-gray-500">{t('cafeteria.providerCode')}</span><span className="font-mono font-medium text-gray-900 dark:text-white">{provider.code}</span></div>
                    <div className={rowCls}><span className="text-gray-500">{t('cafeteria.nameEn')}</span><span className="font-medium text-gray-900 dark:text-white">{provider.name_en}</span></div>
                    <div className={rowCls}><span className="text-gray-500">{t('cafeteria.nameAm')}</span><span className="text-gray-700 dark:text-slate-300">{provider.name_am ?? '—'}</span></div>
                    <div className={rowCls}><span className="text-gray-500">{t('cafeteria.contactPerson')}</span><span className="text-gray-700 dark:text-slate-300">{provider.contact_person ?? '—'}</span></div>
                    <div className={rowCls}><span className="text-gray-500">{t('cafeteria.phoneNumber')}</span><span className="text-gray-700 dark:text-slate-300">{provider.phone_number ?? '—'}</span></div>
                    <div className={rowCls}><span className="text-gray-500">{t('cafeteria.location')}</span><span className="text-gray-700 dark:text-slate-300">{provider.location ?? '—'}</span></div>
                    <div className={rowCls}><span className="text-gray-500">{t('cafeteria.isActive')}</span><StatusBadge status={provider.is_active ? 'active' : 'inactive'} label={provider.is_active ? t('common.active') : t('common.inactive')} /></div>
                </div>

                {/* Branches */}
                <div className={cardCls}>
                    <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('cafeteria.branches')}
                            {branches.length > 0 && (
                                <span className="ml-2 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-900/30 dark:text-[color:var(--color-primary)]">
                                    {branches.length}
                                </span>
                            )}
                        </h2>
                        {can.update && (
                            <Link
                                href={route('cafeteria.providers.branches.create', provider.id)}
                                className="inline-flex items-center gap-1 rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-xs font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                            >
                                + {t('cafeteria.addBranch')}
                            </Link>
                        )}
                    </div>

                    {branches.length === 0 ? (
                        <p className="px-6 py-5 text-sm text-gray-400 dark:text-slate-500">
                            {t('cafeteria.noBranches')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-gray-50 dark:bg-slate-800/50">
                                        <th className={thCls}>{t('cafeteria.branchCode')}</th>
                                        <th className={thCls}>{t('cafeteria.nameEn')}</th>
                                        <th className={thCls}>{t('common.organization')}</th>
                                        <th className={thCls}>{t('cafeteria.location')}</th>
                                        <th className={`${thCls} text-center`}>{t('cafeteria.isActive')}</th>
                                        <th className="px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {branches.map(b => (
                                        <tr key={b.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className="px-4 py-3 font-mono text-xs text-gray-700 dark:text-slate-300">{b.code}</td>
                                            <td className="px-4 py-3">
                                                <div className="font-medium text-gray-900 dark:text-slate-100">{b.name_en}</div>
                                                {b.name_am && <div className="text-xs text-gray-500">{b.name_am}</div>}
                                            </td>
                                            <td className={tdCls}>
                                                {b.organization ? (
                                                    <span>{b.organization.name_en} <span className="font-mono text-xs text-gray-400">({b.organization.code})</span></span>
                                                ) : '—'}
                                            </td>
                                            <td className={tdCls}>{b.location ?? '—'}</td>
                                            <td className="px-4 py-3 text-center">
                                                <StatusBadge status={b.is_active ? 'active' : 'inactive'} label={b.is_active ? t('common.active') : t('common.inactive')} />
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                {can.update && (
                                                    <div className="flex justify-end gap-3">
                                                        <Link
                                                            href={route('cafeteria.providers.branches.edit', { cafeteriaProvider: provider.id, branch: b.id })}
                                                            className="text-xs text-[color:var(--color-primary)] hover:underline"
                                                        >
                                                            {t('common.edit')}
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() => archiveBranch(b.id)}
                                                            className="text-xs text-red-600 hover:underline"
                                                        >
                                                            {t('common.archive')}
                                                        </button>
                                                    </div>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {/* Admin users — managed in Settings */}
                <div className={cardCls}>
                    <div className="flex items-center justify-between border-b border-gray-100 px-6 py-4 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('cafeteria.providerUsers')}
                        </h2>
                        {can.manageUsers && (
                            <Link
                                href={route('cafeteria.settings.index') + '?tab=provider-users'}
                                className="text-xs font-medium text-[color:var(--color-primary)] hover:text-[color:var(--color-primary-hover)] dark:text-[color:var(--color-primary)] dark:hover:text-[color:var(--color-primary-hover)]"
                            >
                                {t('cafeteria.manageAdminUsers')} →
                            </Link>
                        )}
                    </div>

                    {adminUsers.length === 0 ? (
                        <p className="px-6 py-5 text-sm text-gray-400 dark:text-slate-500">
                            {t('cafeteria.noAdminUsers')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="bg-gray-50 dark:bg-slate-800/50">
                                        <th className={thCls}>{t('cafeteria.providerUser')}</th>
                                        <th className={thCls}>{t('cafeteria.branches')}</th>
                                        <th className={thCls}>{t('common.organization')}</th>
                                        <th className={thCls}>{t('cafeteria.providerRole')}</th>
                                        <th className={`${thCls} text-center`}>{t('common.active')}</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                                    {adminUsers.map(a => (
                                        <tr key={a.id} className="hover:bg-gray-50 dark:hover:bg-slate-800/40">
                                            <td className={tdCls}>
                                                <div className="font-medium text-gray-900 dark:text-slate-100">{a.user.name}</div>
                                                <div className="text-xs text-gray-500">{a.user.email}</div>
                                            </td>
                                            <td className={tdCls}>
                                                {a.branch ? (
                                                    <span className="font-medium">{a.branch.name_en} <span className="font-mono text-xs text-gray-400">({a.branch.code})</span></span>
                                                ) : <span className="text-gray-400">—</span>}
                                            </td>
                                            <td className={tdCls}>
                                                {a.organization ? (
                                                    <span>{a.organization.name_en} <span className="font-mono text-xs text-gray-400">({a.organization.code})</span></span>
                                                ) : <span className="text-gray-400">—</span>}
                                            </td>
                                            <td className={tdCls}>{a.provider_role ?? 'operator'}</td>
                                            <td className="px-4 py-3 text-center">
                                                <StatusBadge
                                                    status={a.is_active ? 'active' : 'inactive'}
                                                    label={a.is_active ? t('common.active') : t('common.inactive')}
                                                />
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
