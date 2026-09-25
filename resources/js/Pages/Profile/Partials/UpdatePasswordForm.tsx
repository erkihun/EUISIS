import InputError from '@/Components/InputError';
import PasswordPolicyChecklist from '@/Components/PasswordPolicyChecklist';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import { Transition } from '@headlessui/react';
import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';
import { useLocale } from '@/hooks/useLocale';

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
            <header>
                <h2 className="text-lg font-semibold text-gray-900 dark:text-gray-100">{t('profile.securityPassword')}</h2>
                <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{t('profile.passwordHelp')}</p>
            </header>

            <form onSubmit={updatePassword} className="mt-6 space-y-4">
                <div>
                    <label htmlFor="current_password" className="block text-xs font-medium text-gray-600 dark:text-slate-400">{t('profile.currentPassword')}</label>
                    <TextInput
                        id="current_password"
                        ref={currentPasswordInput}
                        value={data.current_password}
                        onChange={(e) => setData('current_password', e.target.value)}
                        type="password"
                        className="mt-1 block w-full"
                        autoComplete="current-password"
                    />
                    <InputError message={errors.current_password} className="mt-2" />
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <div>
                        <label htmlFor="password" className="block text-xs font-medium text-gray-600 dark:text-slate-400">{t('profile.newPassword')}</label>
                        <TextInput
                            id="password"
                            ref={passwordInput}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            type="password"
                            className="mt-1 block w-full"
                            autoComplete="new-password"
                        />
                        <InputError message={errors.password} className="mt-2" />
                    </div>

                    <div>
                        <label htmlFor="password_confirmation" className="block text-xs font-medium text-gray-600 dark:text-slate-400">{t('profile.confirmPassword')}</label>
                        <TextInput
                            id="password_confirmation"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            type="password"
                            className="mt-1 block w-full"
                            autoComplete="new-password"
                        />
                        <InputError message={errors.password_confirmation} className="mt-2" />
                    </div>
                </div>

                <PasswordPolicyChecklist password={data.password} confirmation={data.password_confirmation} personal={[account?.name, account?.email]} />

                <div className="flex items-center justify-end gap-4 border-t border-gray-100 pt-5 dark:border-slate-800">
                    <Transition show={recentlySuccessful} enter="transition ease-in-out" enterFrom="opacity-0" leave="transition ease-in-out" leaveTo="opacity-0">
                        <p className="text-sm text-gray-600 dark:text-gray-400">{t('profile.passwordUpdated')}</p>
                    </Transition>
                    <PrimaryButton disabled={processing}>{t('profile.updatePassword')}</PrimaryButton>
                </div>
            </form>
        </section>
    );
}
