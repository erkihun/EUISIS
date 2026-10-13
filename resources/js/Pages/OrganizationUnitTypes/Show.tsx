import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { useLocale } from '@/hooks/useLocale';

import { actionLinkClassName, buttonClassName } from '@/Components/ui';
type OrgUnitType = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    sort_order: number;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
    can: { update: boolean; archive: boolean; restore: boolean };
};

export default function OrganizationUnitTypesShow({ type }: { type: OrgUnitType }) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('organization-unit-types.index')}
                    title={type.name_en}
                    description={type.code}
                    actions={
                        type.can.update ? (
                            <Link
                                href={route('organization-unit-types.edit', type.id)}
                                className={buttonClassName({ variant: 'primary', size: 'sm' })}
                            >
                                {t('common.edit')}
                            </Link>
                        ) : undefined
                    }
                />
            }
        >
            <Head title={type.name_en} />

            <div className="space-y-6">
                <div className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                    <dl className="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt className="font-medium text-[color:var(--app-muted-foreground)]">{t('common.code')}</dt>
                            <dd className="mt-1 font-mono">{type.code}</dd>
                        </div>
                        <div>
                            <dt className="font-medium text-[color:var(--app-muted-foreground)]">{t('common.status')}</dt>
                            <dd className="mt-1">
                                <StatusBadge status={type.is_active ? 'active' : 'archived'} />
                            </dd>
                        </div>
                        <div>
                            <dt className="font-medium text-[color:var(--app-muted-foreground)]">{t('organizationUnitTypes.nameEn')}</dt>
                            <dd className="mt-1">{type.name_en}</dd>
                        </div>
                        {type.name_am && (
                            <div>
                                <dt className="font-medium text-[color:var(--app-muted-foreground)]">{t('organizationUnitTypes.nameAm')}</dt>
                                <dd className="mt-1">{type.name_am}</dd>
                            </div>
                        )}
                        <div>
                            <dt className="font-medium text-[color:var(--app-muted-foreground)]">{t('organizationUnitTypes.sortOrder')}</dt>
                            <dd className="mt-1">{type.sort_order}</dd>
                        </div>
                        {type.description_en && (
                            <div className="col-span-2">
                                <dt className="font-medium text-[color:var(--app-muted-foreground)]">{t('organizationUnitTypes.descriptionEn')}</dt>
                                <dd className="mt-1">{type.description_en}</dd>
                            </div>
                        )}
                        {type.description_am && (
                            <div className="col-span-2">
                                <dt className="font-medium text-[color:var(--app-muted-foreground)]">{t('organizationUnitTypes.descriptionAm')}</dt>
                                <dd className="mt-1">{type.description_am}</dd>
                            </div>
                        )}
                    </dl>
                </div>

                <div className="flex">
                    <Link
                        href={route('organization-unit-types.index')}
                        className={actionLinkClassName({ tone: 'neutral', size: 'sm' })}
                    >
                        {t('organizationUnitTypes.backToList')}
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
