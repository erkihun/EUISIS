import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { PermissionsCreateProps } from '@/court/types/access';
import PermissionForm from './Partials/PermissionForm';

/** A new permission: its system name and a label and description in each locale. */
export default function PermissionsCreate({ locales, primaryLocale }: PermissionsCreateProps) {
    const { t } = useLocale();
    const title = t('cm.permissions.create.title');

    return (
        <AuthenticatedLayout contentWidth="form" header={(
            <PageHeader title={title} description={t('cm.permissions.create.name_hint')}
                actions={<Link href={route('court.permissions.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.permissions.create.cancel_button')}</Link>} />
        )}>
            <Head title={title} />
            <PermissionForm mode="create" locales={locales} primaryLocale={primaryLocale} />
        </AuthenticatedLayout>
    );
}
