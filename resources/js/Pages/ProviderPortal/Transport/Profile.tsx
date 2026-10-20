import { useForm, usePage } from '@inertiajs/react';
import { Alert, Button, Card, FormField, Input } from '@euisis/ui';
import type { FormEvent } from 'react';
import TransportProviderLayout from '@/Layouts/TransportProviderLayout';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

type PortalUser = { id: string; name: string | null; email: string | null; username: string | null; phone_number: string | null };

/**
 * A transport provider user's own account. Admin-issued (temporary) passwords
 * are replaced here before anything else in the portal opens.
 */
export default function Profile({ user }: { user: PortalUser }) {
    const { t } = useLocale();
    const mustChange = Boolean(usePage<PageProps<{ providerPortal?: { user?: { must_change_password?: boolean } } | null }>>().props.providerPortal?.user?.must_change_password);
    const profile = useForm({ name: user.name ?? '', email: user.email ?? '', phone_number: user.phone_number ?? '' });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });

    function saveProfile(event: FormEvent) {
        event.preventDefault();
        profile.patch(route('provider.portal.profile.update'), { preserveScroll: true });
    }

    function savePassword(event: FormEvent) {
        event.preventDefault();
        password.patch(route('provider.portal.profile.password'), { preserveScroll: true, onSuccess: () => password.reset() });
    }

    return (
        <TransportProviderLayout title={t('providerPortal.profile')}>
            <div className="mx-auto max-w-2xl space-y-6">
                {mustChange && <Alert tone="warning">{t('auth.must_change_password_notice')}</Alert>}
                <form onSubmit={savePassword}>
                    <Card className="space-y-4">
                        <h2 className="text-sm font-semibold">{t('providerPortal.changePassword')}</h2>
                        <FormField label={t('providerPortal.currentPassword')} required error={password.errors.current_password}>
                            {({ id, invalid }) => <Input id={id} type="password" autoComplete="current-password" value={password.data.current_password} onChange={(e) => password.setData('current_password', e.target.value)} aria-invalid={invalid} />}
                        </FormField>
                        <FormField label={t('providerPortal.newPassword')} required error={password.errors.password}>
                            {({ id, invalid }) => <Input id={id} type="password" autoComplete="new-password" value={password.data.password} onChange={(e) => password.setData('password', e.target.value)} aria-invalid={invalid} />}
                        </FormField>
                        <FormField label={t('providerPortal.confirmNewPassword')} required error={password.errors.password_confirmation}>
                            {({ id, invalid }) => <Input id={id} type="password" autoComplete="new-password" value={password.data.password_confirmation} onChange={(e) => password.setData('password_confirmation', e.target.value)} aria-invalid={invalid} />}
                        </FormField>
                        <PasswordPolicyChecklist password={password.data.password} confirmation={password.data.password_confirmation} />
                        <div className="flex justify-end"><Button type="submit" variant="primary" disabled={password.processing}>{t('providerPortal.updatePassword')}</Button></div>
                    </Card>
                </form>
                {!mustChange && (
                    <form onSubmit={saveProfile}>
                        <Card className="space-y-4">
                            <h2 className="text-sm font-semibold">{t('providerPortal.editProfile')}</h2>
                            <FormField label={t('common.name')} required error={profile.errors.name}>
                                {({ id, invalid }) => <Input id={id} value={profile.data.name} onChange={(e) => profile.setData('name', e.target.value)} aria-invalid={invalid} />}
                            </FormField>
                            <FormField label={t('common.email')} error={profile.errors.email}>
                                {({ id, invalid }) => <Input id={id} type="email" value={profile.data.email} onChange={(e) => profile.setData('email', e.target.value)} aria-invalid={invalid} />}
                            </FormField>
                            <FormField label={t('providerPortal.phoneNumber')} error={profile.errors.phone_number}>
                                {({ id, invalid }) => <Input id={id} type="tel" value={profile.data.phone_number} onChange={(e) => profile.setData('phone_number', e.target.value)} aria-invalid={invalid} />}
                            </FormField>
                            <div className="flex justify-end"><Button type="submit" variant="primary" disabled={profile.processing}>{t('providerPortal.saveChanges')}</Button></div>
                        </Card>
                    </form>
                )}
            </div>
        </TransportProviderLayout>
    );
}
