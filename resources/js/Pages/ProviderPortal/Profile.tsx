import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import { router, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Provider = {
    id: string; code: string; name_en: string; name_am?: string | null;
    contact_person?: string | null; phone_number?: string | null;
    email?: string | null; location?: string | null;
};
type PortalUser = {
    id: string; name?: string | null; email?: string | null;
    username?: string | null; phone_number?: string | null;
};

// ── Shared styles ─────────────────────────────────────────────────────────────
const inputCls = 'mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400/30 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-60';
const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300';
const errorCls = 'mt-1 text-xs text-red-600 dark:text-red-400';

function Card({ title, icon, children }: { title: string; icon: string; children: React.ReactNode }) {
    return (
        <div className="overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div className="flex items-center gap-3 border-b border-gray-100 px-5 py-4 dark:border-slate-800">
                <span className="flex h-9 w-9 items-center justify-center rounded-card bg-orange-100 text-lg dark:bg-orange-900/30">{icon}</span>
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{title}</h2>
            </div>
            <div className="px-5 py-5">{children}</div>
        </div>
    );
}

function InfoRow({ label, value }: { label: string; value?: string | null }) {
    if (!value) return null;
    return (
        <div className="flex items-start justify-between border-b border-gray-100 py-3 last:border-0 dark:border-slate-800">
            <span className="text-sm text-gray-500 dark:text-slate-400">{label}</span>
            <span className="max-w-[60%] text-right text-sm font-medium text-gray-900 dark:text-slate-100">{value}</span>
        </div>
    );
}

// ── Edit profile form ─────────────────────────────────────────────────────────
function EditProfileForm({ user }: { user: PortalUser }) {
    const { t } = useLocale();
    const form = useForm({
        name:         user.name ?? '',
        email:        user.email ?? '',
        phone_number: user.phone_number ?? '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.patch(route('provider.portal.profile.update'), { preserveScroll: true });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div>
                <label className={labelCls}>{t('common.name')}</label>
                <input
                    className={inputCls}
                    value={form.data.name}
                    onChange={e => form.setData('name', e.target.value)}
                    required
                />
                {form.errors.name && <p className={errorCls}>{form.errors.name}</p>}
            </div>
            <div>
                <label className={labelCls}>{t('common.email')}</label>
                <input
                    type="email"
                    className={inputCls}
                    value={form.data.email}
                    onChange={e => form.setData('email', e.target.value)}
                />
                {form.errors.email && <p className={errorCls}>{form.errors.email}</p>}
            </div>
            <div>
                <label className={labelCls}>{t('providerPortal.phoneNumber')}</label>
                <input
                    type="tel"
                    className={inputCls}
                    value={form.data.phone_number}
                    onChange={e => form.setData('phone_number', e.target.value)}
                />
                {form.errors.phone_number && <p className={errorCls}>{form.errors.phone_number}</p>}
            </div>
            {user.username && (
                <div>
                    <label className={labelCls}>Username</label>
                    <input className={inputCls} value={user.username} disabled />
                </div>
            )}
            <div className="flex justify-end pt-1">
                <button
                    type="submit"
                    disabled={form.processing}
                    className="rounded-lg bg-orange-500 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-600 disabled:opacity-60"
                >
                    {form.processing ? '…' : t('providerPortal.saveChanges')}
                </button>
            </div>
        </form>
    );
}

// ── Change password form ──────────────────────────────────────────────────────
function ChangePasswordForm() {
    const { t } = useLocale();
    const form = useForm({
        current_password:      '',
        password:              '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.patch(route('provider.portal.profile.password'), {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <div>
                <label className={labelCls}>{t('providerPortal.currentPassword')}</label>
                <input
                    type="password"
                    className={inputCls}
                    value={form.data.current_password}
                    onChange={e => form.setData('current_password', e.target.value)}
                    required
                    autoComplete="current-password"
                />
                {form.errors.current_password && <p className={errorCls}>{form.errors.current_password}</p>}
            </div>
            <div>
                <label className={labelCls}>{t('providerPortal.newPassword')}</label>
                <input
                    type="password"
                    className={inputCls}
                    value={form.data.password}
                    onChange={e => form.setData('password', e.target.value)}
                    required
                    minLength={8}
                    autoComplete="new-password"
                />
                {form.errors.password && <p className={errorCls}>{form.errors.password}</p>}
            </div>
            <div>
                <label className={labelCls}>{t('providerPortal.confirmNewPassword')}</label>
                <input
                    type="password"
                    className={inputCls}
                    value={form.data.password_confirmation}
                    onChange={e => form.setData('password_confirmation', e.target.value)}
                    required
                    minLength={8}
                    autoComplete="new-password"
                />
                {form.errors.password_confirmation && <p className={errorCls}>{form.errors.password_confirmation}</p>}
            </div>
            <div className="flex justify-end pt-1">
                <button
                    type="submit"
                    disabled={form.processing}
                    className="rounded-lg bg-orange-500 px-5 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-600 disabled:opacity-60"
                >
                    {form.processing ? '…' : t('providerPortal.updatePassword')}
                </button>
            </div>
        </form>
    );
}

// ── Main page ─────────────────────────────────────────────────────────────────
export default function Profile({ providers, selected_provider_id, provider, user }: {
    providers: Provider[];
    selected_provider_id: string | null;
    provider: Provider;
    user: PortalUser;
}) {
    const { t } = useLocale();

    return (
        <CafeteriaProviderPortalLayout
            title={t('providerPortal.profile')}
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <div className="mx-auto max-w-2xl space-y-5">

                {/* Provider info — read-only */}
                <Card title={t('providerPortal.providerDetails')} icon="🏪">
                    <InfoRow label="Name"                             value={provider.name_en} />
                    <InfoRow label="Code"                             value={provider.code} />
                    <InfoRow label={t('cafeteria.contactPerson')}     value={provider.contact_person} />
                    <InfoRow label={t('cafeteria.phoneNumber')}       value={provider.phone_number} />
                    <InfoRow label={t('common.email')}                value={provider.email} />
                    <InfoRow label={t('cafeteria.location')}          value={provider.location} />
                </Card>

                {/* Edit own profile */}
                <Card title={t('providerPortal.editProfile')} icon="✏️">
                    <EditProfileForm user={user} />
                </Card>

                {/* Change password */}
                <Card title={t('providerPortal.changePassword')} icon="🔑">
                    <ChangePasswordForm />
                </Card>

            </div>
        </CafeteriaProviderPortalLayout>
    );
}
