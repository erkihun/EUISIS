import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';

type OrgType = {
    id: string;
    code: string;
    prefix: string | null;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    is_active: boolean;
    sort_order: number;
    organizations_count: number;
    created_at: string | null;
    updated_at: string | null;
    can: { update: boolean; archive: boolean; restore: boolean };
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-slate-800 dark:bg-slate-950/50">
            <dt className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-slate-400">{label}</dt>
            <dd className="mt-1 text-sm font-semibold text-gray-900 dark:text-slate-100">{value}</dd>
        </div>
    );
}

export default function ShowOrganizationType({ type }: { type: OrgType }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('organization-types.index')}
                    title={type.name_en}
                    description={t('organizationTypes.organizationType')}
                    actions={
                        <div className="flex items-center gap-2">
                            <Link
                                href={route('organization-types.index')}
                                className="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800"
                            >
                                {t('common.back')}
                            </Link>
                            {type.can.update && (
                                <Link
                                    href={route('organization-types.edit', type.id)}
                                    className="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700"
                                >
                                    {t('common.edit')}
                                </Link>
                            )}
                        </div>
                    }
                />
            }
        >
            <Head title={type.name_en} />

            <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <dl className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <Detail label={t('organizationTypes.code')} value={<span className="font-mono">{type.code}</span>} />
                    <Detail
                        label={t('organizationTypes.prefix')}
                        value={
                            type.prefix ? (
                                <span className="font-mono">{type.prefix}</span>
                            ) : (
                                <span className="text-gray-500 dark:text-slate-400">{t('organizationTypes.noPrefix')}</span>
                            )
                        }
                    />
                    <Detail label={t('common.status')} value={<StatusBadge status={type.is_active ? 'active' : 'archived'} />} />
                    <Detail label={t('organizationTypes.nameEn')} value={type.name_en} />
                    <Detail label={t('organizationTypes.nameAm')} value={type.name_am ?? t('organizationTypes.notProvided')} />
                    <Detail label={t('organizationTypes.organizationsCount')} value={type.organizations_count} />
                    <Detail label={t('organizationTypes.sortOrder')} value={type.sort_order} />
                    <Detail label={t('common.createdAt')} value={<LocalizedDateDisplay value={type.created_at} withTime />} />
                    <Detail label={t('common.updatedAt')} value={<LocalizedDateDisplay value={type.updated_at} withTime />} />
                </dl>

                {(type.description_en || type.description_am) && (
                    <div className="mt-6 grid gap-4 md:grid-cols-2">
                        {type.description_en && <Detail label={t('organizationTypes.descriptionEn')} value={type.description_en} />}
                        {type.description_am && <Detail label={t('organizationTypes.descriptionAm')} value={type.description_am} />}
                    </div>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
