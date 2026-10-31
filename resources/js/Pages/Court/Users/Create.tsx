import { Head, Link } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Alert, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { UsersCreateProps } from '@/court/types/people';
import UserForm from './Partials/UserForm';

/**
 * A new staff account: identity, contact details, roles and profile images; the person gets a reset link.
 * From the EUISIS staff roster the employee's name and number come filled in and the account is linked.
 */
export default function UsersCreate({ roles, euisisPrefill = null, euisisPrefillError = null }: UsersCreateProps) {
    const { t } = useLocale();

    return (
        <AuthenticatedLayout header={(
            <PageHeader title={t('cm.users.create_account_title')} description={t('cm.users.create_account_subtitle')}
                actions={<Link href={route(euisisPrefill || euisisPrefillError ? 'court.users.euisis.index' : 'court.users.index')} className={buttonClassName({ variant: 'outline' })}>{t('cm.users.back_to_users')}</Link>} />
        )}>
            <Head title={t('cm.users.new_user_title')} />
            {euisisPrefillError && <Alert tone="danger" className="mb-4">{euisisPrefillError}</Alert>}
            {euisisPrefill && (
                <Alert tone="info" className="mb-4">
                    {t('cm.euisis_staff.create.from_euisis').replace(':name', euisisPrefill.name).replace(':number', euisisPrefill.employee_number)}
                </Alert>
            )}
            <UserForm mode="create" roles={roles} euisisPrefill={euisisPrefill} />
        </AuthenticatedLayout>
    );
}
