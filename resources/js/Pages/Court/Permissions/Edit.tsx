import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PermissionsEditProps } from '@/court/types/access';
import PermissionForm from './Partials/PermissionForm';

/** An existing permission: its system name and its label and description per locale. */
export default function PermissionsEdit({ permission, locales, primaryLocale }: PermissionsEditProps) {
    const { t } = useLocale();
    const title = t('cm.permissions.edit.title');

    return (
        <AuthenticatedLayout contentWidth="form" header={(
            <PageHeader title={title} description={permission.name}
                actions={<Link href={route('court.permissions.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.permissions.edit.back_button')}</Link>} />
        )}>
            <Head title={title} />
            <PermissionForm mode="edit" permission={permission} locales={locales} primaryLocale={primaryLocale} />
        </AuthenticatedLayout>
    );
}
