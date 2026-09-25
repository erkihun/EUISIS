import { Link, useForm } from '@inertiajs/react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import InfoList from './InfoList';
import { Block, FieldErrors, inputCls, labelCls, linkBtn, primaryBtn } from './ui';

export type AccountInfo = {
    name: string;
    email: string;
    roles: string[];
    status: string;
    last_login_at: string | null;
    mfa_enabled: boolean;
    mfa_required: boolean;
};

type Translate = (path: string, params?: Record<string, string>) => string;

/**
 * The sign-in account, as the last block of My Profile.
 *
 * Replaces the separate /profile page for employee-linked accounts, so a
 * person has one profile. Personal facts (name, phone, National ID, photo)
 * are NOT edited here: they live on the employee record above, under HR and
 * verification rules. This block only covers how the person signs in.
 */
export default function AccountSection({ account, l }: { account: AccountInfo; l: Translate }) {
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });

    function updatePassword(event: React.FormEvent) {
        event.preventDefault();
        password.put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => password.reset(),
            onError: () => password.reset('password', 'password_confirmation'),
        });
    }

    const statusLabel = l(`account.statuses.${account.status}`);

    return (
        <div id="account" className="scroll-mt-4">
            <Block title={l('account.title')} description={l('account.intro')}>
                <div className="space-y-5">
                    <InfoList
                        printedLabel=""
                        rows={[
                            {
                                key: 'email',
                                label: l('account.sign_in_email'),
                                value: (
                                    <>
                                        <span className="break-all">{account.email}</span>
                                        <span className="block text-xs font-normal text-gray-500 dark:text-slate-400">{l('account.sign_in_email_note')}</span>
                                    </>
                                ),
                            },
                            { key: 'status', label: l('account.status'), value: statusLabel.startsWith('account.') ? account.status : statusLabel },
                            { key: 'roles', label: l('account.roles'), value: account.roles.join(', ') },
                            {
                                key: 'last_login',
                                label: l('account.last_login'),
                                value: account.last_login_at ? <LocalizedDateDisplay value={account.last_login_at} withTime /> : l('account.never'),
                            },
                            {
                                key: 'mfa',
                                label: l('account.two_factor'),
                                value: (
                                    <span className="flex flex-wrap items-center gap-2">
                                        {account.mfa_enabled ? l('account.two_factor_on') : l('account.two_factor_off')}
                                        {!account.mfa_enabled && account.mfa_required && (
                                            <span className="text-xs font-normal text-amber-700 dark:text-amber-400">{l('account.two_factor_required')}</span>
                                        )}
                                        {!account.mfa_enabled && (
                                            <Link href={route('mfa.setup')} className={`${linkBtn} text-xs`}>{l('account.two_factor_setup')}</Link>
                                        )}
                                    </span>
                                ),
                            },
                        ]}
                    />

                    <form onSubmit={updatePassword} className="border-t border-gray-100 pt-4 dark:border-slate-800">
                        <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{l('account.password')}</h3>
                        <div className="mt-2 grid gap-3 sm:grid-cols-3">
                            <div>
                                <label htmlFor="pw-current" className={labelCls}>{l('account.current_password')}</label>
                                <input id="pw-current" type="password" autoComplete="current-password" required className={inputCls} value={password.data.current_password} onChange={(e) => password.setData('current_password', e.target.value)} />
                            </div>
                            <div>
                                <label htmlFor="pw-new" className={labelCls}>{l('account.new_password')}</label>
                                <input id="pw-new" type="password" autoComplete="new-password" required className={inputCls} value={password.data.password} onChange={(e) => password.setData('password', e.target.value)} />
                            </div>
                            <div>
                                <label htmlFor="pw-confirm" className={labelCls}>{l('account.confirm_password')}</label>
                                <input id="pw-confirm" type="password" autoComplete="new-password" required className={inputCls} value={password.data.password_confirmation} onChange={(e) => password.setData('password_confirmation', e.target.value)} />
                            </div>
                        </div>
                        <FieldErrors errors={password.errors as Record<string, string | undefined>} />
                        <div className="mt-3 flex flex-wrap items-center justify-end gap-3">
                            {password.recentlySuccessful && <span role="status" className="text-sm text-emerald-700 dark:text-emerald-400">{l('account.password_updated')}</span>}
                            <button className={`${primaryBtn} w-full sm:w-auto`} disabled={password.processing}>{l('account.update_password')}</button>
                        </div>
                    </form>
                </div>
            </Block>
        </div>
    );
}
