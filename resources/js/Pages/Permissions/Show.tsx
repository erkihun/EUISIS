import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';

type Permission = {
    id: number;
    name: string;
    guard_name: string;
    label_en: string | null;
    label_am: string | null;
    description_en: string | null;
    description_am: string | null;
    group: string | null;
    sort_order: number;
    is_system: boolean;
    roles_count: number | null;
    created_at: string | null;
    updated_at: string | null;
    can: { view: boolean; update: boolean; delete: boolean };
};

type RoleRef = { id: number; name: string };

export default function ShowPermission({
    permission,
    roles,
}: {
    permission: Permission;
    roles: RoleRef[];
}) {
    const { t, locale } = useLocale();

    const label = (locale === 'am' ? permission.label_am : null) ?? permission.label_en ?? permission.name;
    const description = (locale === 'am' ? permission.description_am : null) ?? permission.description_en;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('permissions.index')}
                    title={label}
                    description={permission.name}
                    actions={
                        permission.can?.update ? (
                            <Link
                                href={route('permissions.edit', permission.id)}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-900"
                            >
                                {t('permissions.editPermission')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={label} />

            <div className="mx-auto max-w-2xl space-y-5">
                <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <h2 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('permissions.basicInformation')}
                    </h2>
                    <dl className="space-y-4 text-sm">
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('permissions.permissionKey')}</dt>
                            <dd className="mt-1 font-mono text-gray-900 dark:text-slate-100">{permission.name}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('permissions.labelEn')}</dt>
                            <dd className="mt-1 text-gray-900 dark:text-slate-100">{permission.label_en ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('permissions.labelAm')}</dt>
                            <dd className="mt-1 text-gray-900 dark:text-slate-100" dir="auto">{permission.label_am ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('permissions.descriptionEn')}</dt>
                            <dd className="mt-1 text-gray-700 dark:text-slate-300">{permission.description_en ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('permissions.descriptionAm')}</dt>
                            <dd className="mt-1 text-gray-700 dark:text-slate-300" dir="auto">{permission.description_am ?? '—'}</dd>
                        </div>
                        <div className="flex gap-8">
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('permissions.permissionGroup')}</dt>
                                <dd className="mt-1 text-gray-900 dark:text-slate-100">{permission.group ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('permissions.guardName')}</dt>
                                <dd className="mt-1 font-mono text-gray-900 dark:text-slate-100">{permission.guard_name}</dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('permissions.sortOrder')}</dt>
                                <dd className="mt-1 text-gray-900 dark:text-slate-100">{permission.sort_order}</dd>
                            </div>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('permissions.systemPermission')}</dt>
                            <dd className="mt-1">
                                {permission.is_system ? (
                                    <span className="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                                        {t('permissions.systemPermission')}
                                    </span>
                                ) : (
                                    <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-300">
                                        {t('permissions.customPermission')}
                                    </span>
                                )}
                            </dd>
                        </div>
                        <div className="flex gap-8">
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('common.createdAt')}</dt>
                                <dd className="mt-1 text-gray-900 dark:text-slate-100">
                                    <LocalizedDateDisplay value={permission.created_at} withTime />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('common.updatedAt')}</dt>
                                <dd className="mt-1 text-gray-900 dark:text-slate-100">
                                    <LocalizedDateDisplay value={permission.updated_at} withTime />
                                </dd>
                            </div>
                        </div>
                    </dl>
                </div>

                {roles.length > 0 && (
                    <div className="rounded-2xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-3 text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('permissions.usedByRoles')} ({roles.length})
                        </h2>
                        <ul className="flex flex-wrap gap-2">
                            {roles.map((r) => (
                                <li key={r.id}>
                                    <Link
                                        href={route('roles.edit', r.id)}
                                        className="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-700 hover:bg-blue-200 dark:bg-blue-900/30 dark:text-blue-400 dark:hover:bg-blue-900/50"
                                    >
                                        {r.name}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="flex justify-start">
                    <Link
                        href={route('permissions.index')}
                        className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        {t('common.cancel')}
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
