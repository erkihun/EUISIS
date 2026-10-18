import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import { Transition } from '@headlessui/react';
import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { Button, CardFooter, FormField, Input } from '@/Components/ui';

export default function UpdatePasswordForm({ className = '' }: { className?: string }) {
    const { t } = useLocale();
    const account = (usePage().props as { auth?: { user?: { name?: string; email?: string } } }).auth?.user;
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const { data, setData, errors, put, reset, processing, recentlySuccessful } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword: FormEventHandler = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (validationErrors) => {
                if (validationErrors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current?.focus();
                }

                if (validationErrors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current?.focus();
                }
            },
        });
    };

    return (
        <section className={className}>
            <form onSubmit={updatePassword} className="space-y-5">
                <FormField id="current_password" label={t('profile.currentPassword')} required error={errors.current_password} className="sm:max-w-[calc(50%-0.625rem)]">
                    {({ id, describedBy, invalid }) => (
                        <Input
                            id={id}
                            ref={currentPasswordInput}
                            value={data.current_password}
                            onChange={(e) => setData('current_password', e.target.value)}
                            type="password"
                            className="w-full"
                            autoComplete="current-password"
                            aria-describedby={describedBy}
                            aria-invalid={invalid || undefined}
                        />
                    )}
                </FormField>

                <div className="grid gap-5 sm:grid-cols-2">
                    <FormField id="password" label={t('profile.newPassword')} required error={errors.password}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                ref={passwordInput}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                type="password"
                                className="w-full"
                                autoComplete="new-password"
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                            />
                        )}
                    </FormField>

                    <FormField id="password_confirmation" label={t('profile.confirmPassword')} required error={errors.password_confirmation}>
                        {({ id, describedBy, invalid }) => (
                            <Input
                                id={id}
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                type="password"
                                className="w-full"
                                autoComplete="new-password"
                                aria-describedby={describedBy}
                                aria-invalid={invalid || undefined}
                            />
                        )}
                    </FormField>
                </div>

                <PasswordPolicyChecklist password={data.password} confirmation={data.password_confirmation} personal={[account?.name, account?.email]} />

                <CardFooter className="mt-6">
                    <Transition show={recentlySuccessful} enter="transition ease-in-out" enterFrom="opacity-0" leave="transition ease-in-out" leaveTo="opacity-0">
                        <p className="mr-auto text-sm text-(--app-muted-foreground)" role="status">{t('profile.passwordUpdated')}</p>
                    </Transition>
                    <Button type="submit" loading={processing}>{t('profile.updatePassword')}</Button>
                </CardFooter>
            </form>
        </section>
    );
}
