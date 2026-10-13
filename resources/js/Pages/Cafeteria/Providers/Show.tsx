import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useConfirm } from '@/hooks/useConfirm';

import { actionLinkClassName, badgeClassName, buttonClassName, cx, tableBodyClassName, tableCellClassName, tableClassName, tableHeadClassName, tableHeaderCellClassName, tableRowClassName } from '@/Components/ui';
import AppActionMenu from '@/Components/ui/AppActionMenu';
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
    const rowCls = 'flex justify-between border-b border-[color:var(--app-border)] py-3 text-sm last:border-0';
    const thCls = 'px-4 py-3 text-left text-xs font-medium text-[color:var(--app-muted-foreground)]';
    const tdCls = 'px-4 py-3 text-sm text-[color:var(--app-foreground)]';
    const cardCls = 'rounded-card border border-[color:var(--app-border)] bg-[color:var(--app-surface)]';

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
                                className="rounded-lg border border-[color:var(--app-border-strong)] px-3 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                            >
                                {t('common.edit')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <div className="space-y-5">

                {/* Provider details */}
                <div className={`${cardCls} px-6`}>
                    <div className={rowCls}><span className="text-[color:var(--app-muted-foreground)]">{t('cafeteria.providerCode')}</span><span className="font-mono font-medium text-[color:var(--app-foreground)]">{provider.code}</span></div>
                    <div className={rowCls}><span className="text-[color:var(--app-muted-foreground)]">{t('cafeteria.nameEn')}</span><span className="font-medium text-[color:var(--app-foreground)]">{provider.name_en}</span></div>
                    <div className={rowCls}><span className="text-[color:var(--app-muted-foreground)]">{t('cafeteria.nameAm')}</span><span className="text-[color:var(--app-foreground)]">{provider.name_am ?? '—'}</span></div>
                    <div className={rowCls}><span className="text-[color:var(--app-muted-foreground)]">{t('cafeteria.contactPerson')}</span><span className="text-[color:var(--app-foreground)]">{provider.contact_person ?? '—'}</span></div>
                    <div className={rowCls}><span className="text-[color:var(--app-muted-foreground)]">{t('cafeteria.phoneNumber')}</span><span className="text-[color:var(--app-foreground)]">{provider.phone_number ?? '—'}</span></div>
                    <div className={rowCls}><span className="text-[color:var(--app-muted-foreground)]">{t('cafeteria.location')}</span><span className="text-[color:var(--app-foreground)]">{provider.location ?? '—'}</span></div>
                    <div className={rowCls}><span className="text-[color:var(--app-muted-foreground)]">{t('cafeteria.isActive')}</span><StatusBadge status={provider.is_active ? 'active' : 'inactive'} label={provider.is_active ? t('common.active') : t('common.inactive')} /></div>
                </div>

                {/* Branches */}
                <div className={cardCls}>
                    <div className="flex items-center justify-between border-b border-[color:var(--app-border)] px-6 py-4">
                        <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">
                            {t('cafeteria.branches')}
                            {branches.length > 0 && (
                                <span className={badgeClassName('info', 'ml-2')}>
                                    {branches.length}
                                </span>
                            )}
                        </h2>
                        {can.update && (
                            <Link
                                href={route('cafeteria.providers.branches.create', provider.id)}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                + {t('cafeteria.addBranch')}
                            </Link>
                        )}
                    </div>

                    {branches.length === 0 ? (
                        <p className="px-6 py-5 text-sm text-[color:var(--app-muted-foreground)]">
                            {t('cafeteria.noBranches')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClassName}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={thCls}>{t('cafeteria.branchCode')}</th>
                                        <th className={thCls}>{t('cafeteria.nameEn')}</th>
                                        <th className={thCls}>{t('common.organization')}</th>
                                        <th className={thCls}>{t('cafeteria.location')}</th>
                                        <th className={`${thCls} text-center`}>{t('cafeteria.isActive')}</th>
                                        <th className={cx(tableHeaderCellClassName, 'text-left')} />
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {branches.map(b => (
                                        <tr key={b.id} className={tableRowClassName}>
                                            <td className={cx(tableCellClassName, 'font-mono text-xs text-[color:var(--app-foreground)]')}>{b.code}</td>
                                            <td className={tableCellClassName}>
                                                <div className="font-medium text-[color:var(--app-foreground)]">{b.name_en}</div>
                                                {b.name_am && <div className="text-xs text-[color:var(--app-muted-foreground)]">{b.name_am}</div>}
                                            </td>
                                            <td className={tdCls}>
                                                {b.organization ? (
                                                    <span>{b.organization.name_en} <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">({b.organization.code})</span></span>
                                                ) : '—'}
                                            </td>
                                            <td className={tdCls}>{b.location ?? '—'}</td>
                                            <td className={cx(tableCellClassName, 'text-center')}>
                                                <StatusBadge status={b.is_active ? 'active' : 'inactive'} label={b.is_active ? t('common.active') : t('common.inactive')} />
                                            </td>
                                            <td className={cx(tableCellClassName, 'text-right')}>
                                                {can.update && (
                                                    <div className="flex justify-end">
                                                        <AppActionMenu
                                                            items={[
                                                                { label: t('common.edit'), href: route('cafeteria.providers.branches.edit', { cafeteriaProvider: provider.id, branch: b.id }) },
                                                                { label: t('common.archive'), onClick: () => archiveBranch(b.id), variant: 'danger' },
                                                            ]}
                                                        />
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
                    <div className="flex items-center justify-between border-b border-[color:var(--app-border)] px-6 py-4">
                        <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">
                            {t('cafeteria.providerUsers')}
                        </h2>
                        {can.manageUsers && (
                            <Link
                                href={route('cafeteria.settings.index') + '?tab=provider-users'}
                                className={actionLinkClassName()}
                            >
                                {t('cafeteria.manageAdminUsers')} →
                            </Link>
                        )}
                    </div>

                    {adminUsers.length === 0 ? (
                        <p className="px-6 py-5 text-sm text-[color:var(--app-muted-foreground)]">
                            {t('cafeteria.noAdminUsers')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className={tableClassName}>
                                <thead className={tableHeadClassName}>
                                    <tr>
                                        <th className={thCls}>{t('cafeteria.providerUser')}</th>
                                        <th className={thCls}>{t('cafeteria.branches')}</th>
                                        <th className={thCls}>{t('common.organization')}</th>
                                        <th className={thCls}>{t('cafeteria.providerRole')}</th>
                                        <th className={`${thCls} text-center`}>{t('common.active')}</th>
                                    </tr>
                                </thead>
                                <tbody className={tableBodyClassName}>
                                    {adminUsers.map(a => (
                                        <tr key={a.id} className={tableRowClassName}>
                                            <td className={tdCls}>
                                                <div className="font-medium text-[color:var(--app-foreground)]">{a.user.name}</div>
                                                <div className="text-xs text-[color:var(--app-muted-foreground)]">{a.user.email}</div>
                                            </td>
                                            <td className={tdCls}>
                                                {a.branch ? (
                                                    <span className="font-medium">{a.branch.name_en} <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">({a.branch.code})</span></span>
                                                ) : <span className="text-[color:var(--app-muted-foreground)]">—</span>}
                                            </td>
                                            <td className={tdCls}>
                                                {a.organization ? (
                                                    <span>{a.organization.name_en} <span className="font-mono text-xs text-[color:var(--app-muted-foreground)]">({a.organization.code})</span></span>
                                                ) : <span className="text-[color:var(--app-muted-foreground)]">—</span>}
                                            </td>
                                            <td className={tdCls}>{a.provider_role ?? 'operator'}</td>
                                            <td className={cx(tableCellClassName, 'text-center')}>
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
