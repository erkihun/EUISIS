import { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

type NamedRecord = { name: string; code: string } | null;

interface Props {
    profile: {
        name: string;
        email: string;
        role: string;
        status: string;
        provider: NamedRecord;
        cafeteria: NamedRecord;
        last_login_at: string | null;
    };
}

const ROLE_LABEL_KEY: Record<string, string> = {
    provider_admin: 'users.roleProviderAdmin',
    cafeteria_manager: 'users.roleCafeteriaManager',
    scanner: 'users.roleScanner',
    report_viewer: 'users.roleReportViewer',
};

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

export default function Profile({ profile }: Props) {
    const { t } = useLocale();

    const details = useForm({ name: profile.name, email: profile.email });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });

    function submitDetails(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        details.patch('/profile', { preserveScroll: true });
    }

    function submitPassword(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        password.patch('/profile/password', {
            preserveScroll: true,
            onSuccess: () => password.reset(),
        });
    }

    return (
        <AppLayout title={t('profile.title')}>
            <div className="grid gap-6 lg:grid-cols-2">
                {/* Assigned by an administrator — read-only here on purpose. */}
                <section className="rounded-2xl border border-gray-200 bg-white p-5">
                    <h2 className="mb-1 font-semibold text-slate-900">{t('profile.account')}</h2>
                    <p className="mb-4 text-xs text-slate-500">{t('profile.accountHint')}</p>

                    <dl className="space-y-3 text-sm">
                        <div className="flex justify-between gap-3">
                            <dt className="text-slate-500">{t('common.role')}</dt>
                            <dd className="text-right font-medium text-slate-900">
                                {t(ROLE_LABEL_KEY[profile.role] ?? 'common.role')}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-slate-500">{t('common.status')}</dt>
                            <dd className="text-right font-medium text-slate-900">
                                {t(`common.${profile.status}`)}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-slate-500">{t('providers.title')}</dt>
                            <dd className="text-right font-medium text-slate-900">{profile.provider?.name ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-slate-500">{t('extra.cafeteria')}</dt>
                            <dd className="text-right font-medium text-slate-900">{profile.cafeteria?.name ?? '—'}</dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-slate-500">{t('extra.lastLogin')}</dt>
                            <dd className="text-right font-medium text-slate-900">{profile.last_login_at ?? '—'}</dd>
                        </div>
                    </dl>
                </section>

                <section className="rounded-2xl border border-gray-200 bg-white p-5">
                    <h2 className="mb-4 font-semibold text-slate-900">{t('profile.details')}</h2>

                    <form onSubmit={submitDetails} className="space-y-3">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">{t('common.name')}</span>
                            <input
                                className={inputCls}
                                value={details.data.name}
                                onChange={(event) => details.setData('name', event.target.value)}
                            />
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">{t('common.email')}</span>
                            <input
                                type="email"
                                className={inputCls}
                                value={details.data.email}
                                onChange={(event) => details.setData('email', event.target.value)}
                            />
                        </label>

                        {Object.keys(details.errors).length > 0 && (
                            <p className="text-xs text-red-600">{Object.values(details.errors).join(' ')}</p>
                        )}

                        <button
                            type="submit"
                            disabled={details.processing}
                            className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                        >
                            {t('common.save')}
                        </button>
                    </form>
                </section>

                <section className="rounded-2xl border border-gray-200 bg-white p-5 lg:col-span-2">
                    <h2 className="mb-1 font-semibold text-slate-900">{t('profile.changePassword')}</h2>
                    <p className="mb-4 text-xs text-slate-500">{t('profile.changePasswordHint')}</p>

                    <form onSubmit={submitPassword} className="grid gap-3 sm:grid-cols-3">
                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">
                                {t('profile.currentPassword')}
                            </span>
                            <input
                                type="password"
                                autoComplete="current-password"
                                className={inputCls}
                                value={password.data.current_password}
                                onChange={(event) => password.setData('current_password', event.target.value)}
                            />
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">
                                {t('profile.newPassword')}
                            </span>
                            <input
                                type="password"
                                autoComplete="new-password"
                                className={inputCls}
                                value={password.data.password}
                                onChange={(event) => password.setData('password', event.target.value)}
                            />
                        </label>

                        <label className="block">
                            <span className="mb-1 block text-xs font-medium text-slate-600">
                                {t('profile.confirmPassword')}
                            </span>
                            <input
                                type="password"
                                autoComplete="new-password"
                                className={inputCls}
                                value={password.data.password_confirmation}
                                onChange={(event) => password.setData('password_confirmation', event.target.value)}
                            />
                        </label>

                        {Object.keys(password.errors).length > 0 && (
                            <p className="text-xs text-red-600 sm:col-span-3">
                                {Object.values(password.errors).join(' ')}
                            </p>
                        )}

                        <div className="sm:col-span-3">
                            <button
                                type="submit"
                                disabled={password.processing}
                                className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                            >
                                {t('profile.updatePassword')}
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </AppLayout>
    );
}
