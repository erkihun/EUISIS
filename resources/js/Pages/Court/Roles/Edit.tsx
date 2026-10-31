import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { fill } from '@/court/i18n/useAccessForms';
import type { RolesEditProps } from '@/court/types/access';
import RoleForm from './Partials/RoleForm';

/** An existing role: its name, description and the permissions it grants. */
export default function RolesEdit({ role, permissionsCount, permissionGroups }: RolesEditProps) {
    const { t } = useLocale();
    const title = t('cm.roles.edit.title');

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={title} description={fill(t('cm.roles.edit.subtitle'), { name: role.name })}
                actions={<Link href={route('court.roles.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.roles.edit.back')}</Link>} />
        )}>
            <Head title={title} />
            <RoleForm mode="edit" role={role} permissionsCount={permissionsCount} permissionGroups={permissionGroups} />
        </AuthenticatedLayout>
    );
}
