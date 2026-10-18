import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import { Transition } from '@headlessui/react';
import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';
import { useLocale } from '@/hooks/useLocale';
import { Button, FieldError, Input } from '@/Components/ui';

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
            <form onSubmit={updatePassword} className="space-y-4">
                <div>
                    <label htmlFor="current_password" className="block text-xs font-medium text-(--app-muted-foreground)">{t('profile.currentPassword')}</label>
                    <Input
                        id="current_password"
                        ref={currentPasswordInput}
                        value={data.current_password}
                        onChange={(e) => setData('current_password', e.target.value)}
                        type="password"
                        className="mt-1 block w-full"
                        autoComplete="current-password"
                    />
                    <FieldError className="mt-2">{errors.current_password}</FieldError>
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div>
                        <label htmlFor="password" className="block text-xs font-medium text-(--app-muted-foreground)">{t('profile.newPassword')}</label>
                        <Input
                            id="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            type="password"
                            className="mt-1 block w-full"
                            autoComplete="new-password"
                        />
                        <FieldError className="mt-2">{errors.password}</FieldError>
                    </div>

                    <div>
                        <label htmlFor="password_confirmation" className="block text-xs font-medium text-(--app-muted-foreground)">{t('profile.confirmPassword')}</label>
                        <Input
                            id="password_confirmation"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            type="password"
                            className="mt-1 block w-full"
                            autoComplete="new-password"
                        />
                        <FieldError className="mt-2">{errors.password_confirmation}</FieldError>
                    </div>
                </div>

                <PasswordPolicyChecklist password={data.password} confirmation={data.password_confirmation} personal={[account?.name, account?.email]} />

                <div className="flex items-center justify-end gap-4 border-t border-(--app-border) pt-5">
                    <Transition show={recentlySuccessful} enter="transition ease-in-out" enterFrom="opacity-0" leave="transition ease-in-out" leaveTo="opacity-0">
                        <p className="text-sm text-(--app-muted-foreground)">{t('profile.passwordUpdated')}</p>
                    </Transition>
                    <Button disabled={processing}>{t('profile.updatePassword')}</Button>
                </div>
            </form>
        </section>
    );
}
