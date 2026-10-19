import { useState, type FormEvent } from 'react';
import { Link, useForm } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { InfoIcon, ShieldIcon, SmartphoneIcon } from '@/Components/Icons';
import { Button, FormField, Input, badgeClassName, cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import SectionCard from './SectionCard';
import type { SecurityInfo } from './types';

/**
 * The second factor's state, with the one action that applies: start the
 * setup (the MFA routes own the QR code and confirmation) or, when the role
 * does not demand it, turn it off after re-entering the password.
 */
export default function TwoFactorCard({ security }: { security: SecurityInfo }) {
    const { t } = useLocale();
    const [turningOff, setTurningOff] = useState(false);
    const form = useForm({ password: '' });

    const enabled = security.two_factor_enabled;
    const required = security.two_factor_required;

    function submitTurnOff(e: FormEvent) {
        e.preventDefault();
        form.post(route('mfa.disable'), {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setTurningOff(false); },
            onError: () => form.reset('password'),
        });
    }

    return (
        <SectionCard
            id="profile-two-factor-title"
            icon={<ShieldIcon className="h-4.5 w-4.5" />}
            title={t('profile.twoFactor')}
            description={t('profile.twoFactorHelp')}
        >
            <div className="flex flex-wrap items-center gap-5">
                <div className="flex min-w-0 flex-1 basis-72 items-start gap-3.5">
                    <span
                        aria-hidden="true"
                        className={cx(
                            'flex h-11 w-11 shrink-0 items-center justify-center rounded-panel',
                            enabled ? 'bg-primary/10 text-primary' : 'bg-(--app-surface-muted) text-(--app-muted-foreground)',
                        )}
                    >
                        <SmartphoneIcon className="h-5 w-5" />
                    </span>
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2.5">
                            <span className="font-semibold text-(--app-foreground)">{t('profile.twoFactorApp')}</span>
                            <span className={badgeClassName(enabled ? 'success' : 'neutral')}>{enabled ? t('profile.twoFactorOn') : t('profile.twoFactorOff')}</span>
                        </div>
                        {enabled && (
                            <p className="mt-1 text-helper text-(--app-muted-foreground)">
                                {security.two_factor_confirmed_at && (
                                    <>{t('profile.twoFactorEnabledOn')} <LocalizedDateDisplay value={security.two_factor_confirmed_at} className="font-medium text-(--app-foreground)" /></>
                                )}
                                {security.two_factor_confirmed_at && security.two_factor_last_used_at && ' · '}
                                {security.two_factor_last_used_at && (
                                    <>{t('profile.twoFactorLastUsed')} <LocalizedDateDisplay value={security.two_factor_last_used_at} withTime className="font-medium text-(--app-foreground)" /></>
                                )}
                            </p>
                        )}
                        {required && (
                            <p className="mt-2 flex items-start gap-1.5 text-helper text-(--app-muted-foreground)">
                                <InfoIcon className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span>{enabled ? t('profile.twoFactorRequiredNote') : t('profile.twoFactorRequiredSetup')}</span>
                            </p>
                        )}
                    </div>
                </div>

                <div className="flex shrink-0 flex-wrap gap-2">
                    {!enabled && (
                        <Button as={Link} href={route('mfa.setup')} icon={<ShieldIcon className="h-4 w-4" />}>
                            {t('profile.setUpTwoFactor')}
                        </Button>
                    )}
                    {enabled && !required && !turningOff && (
                        <Button variant="outline" onClick={() => setTurningOff(true)}>{t('profile.turnOffTwoFactor')}</Button>
                    )}
                </div>
            </div>

            {turningOff && (
                <form onSubmit={submitTurnOff} className="mt-5 border-t border-(--app-border) pt-5">
                    <FormField
                        id="two-factor-password"
                        label={t('profile.currentPassword')}
                        required
                        description={t('profile.turnOffTwoFactorHelp')}
                        error={form.errors.password}
                        className="sm:max-w-sm"
                    >
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                type="password"
                                autoComplete="current-password"
                                className="w-full"
                                autoFocus
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                            />
                        )}
                    </FormField>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <Button type="submit" variant="destructive" loading={form.processing}>{t('profile.confirmTurnOff')}</Button>
                        <Button variant="ghost" onClick={() => { setTurningOff(false); form.reset(); form.clearErrors(); }}>{t('common.cancel')}</Button>
                    </div>
                </form>
            )}
        </SectionCard>
    );
}
