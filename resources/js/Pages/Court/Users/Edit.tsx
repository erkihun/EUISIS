import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { UsersEditProps } from '@/court/types/people';
import UserForm from './Partials/UserForm';

/** An existing account: identity, contact details, status, an optional new password, roles and profile images. */
export default function UsersEdit({ user, roles, canManage }: UsersEditProps) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={t('cm.users.edit_profile_title')} description={t('cm.users.edit_profile_subtitle')}
                actions={<Link href={route('court.users.show', user.id)} className={buttonClassName({ variant: 'outline' })}>{t('cm.users.view_profile')}</Link>} />
        )}>
            <Head title={t('cm.users.edit_user_title')} />
            <UserForm mode="edit" roles={roles} user={user} canManage={canManage} />
        </AuthenticatedLayout>
    );
}
