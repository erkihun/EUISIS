import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { fill } from '@/court/i18n/useAccessForms';
import type { RolesCreateProps } from '@/court/types/access';
import RoleForm from './Partials/RoleForm';

/** A new role with the permissions it grants, grouped by module. */
export default function RolesCreate({ permissionsCount, permissionGroups }: RolesCreateProps) {
    const { t } = useLocale();
    const title = t('cm.roles.create.title');

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={fill(t('cm.roles.create.subtitle'), { count: permissionsCount })}
                actions={<Link href={route('court.roles.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.roles.create.back')}</Link>} />
        )}>
            <Head title={title} />
            <RoleForm mode="create" permissionsCount={permissionsCount} permissionGroups={permissionGroups} />
        </AuthenticatedLayout>
    );
}
