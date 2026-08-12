import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { Head, Link, router } from '@inertiajs/react';
import { useConfirm } from '@/hooks/useConfirm';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';

type ProviderUser = {
    id: string;
    name: string;
    email: string | null;
    username: string | null;
    phone_number: string | null;
    status: string;
    portal_enabled: boolean;
    must_change_password: boolean;
    last_login_at: string | null;
    suspended_at: string | null;
    suspension_reason: string | null;
    created_at: string | null;
    service_type: { id: string; code: string; name_en: string } | null;
    provider: { id: string; code: string; name: string; status: string } | null;
};

const labelCls = 'text-xs font-medium text-gray-500 dark:text-slate-400';
const valueCls = 'mt-0.5 text-sm text-gray-900 dark:text-slate-100';

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <dt className={labelCls}>{label}</dt>
            <dd className={valueCls}>{children}</dd>
        </div>
    );
}

export default function ShowProviderUser({ providerUser }: { providerUser: ProviderUser }) {
    const { confirm } = useConfirm();

    async function handleDelete() {
        const { confirmed } = await confirm({
            title: 'Delete Provider User',
            description: `Delete "${providerUser.name}"? This cannot be undone.`,
            confirmLabel: 'Delete',
            cancelLabel: 'Cancel',
            variant: 'danger',
        });
        if (confirmed) {
            router.delete(route('provider-users.destroy', providerUser.id));
        }
    }

    async function handleSuspend() {
        const { confirmed } = await confirm({
            title: 'Suspend Provider User',
            description: `Suspend "${providerUser.name}"?`,
            confirmLabel: 'Suspend',
            cancelLabel: 'Cancel',
            variant: 'danger',
        });
        if (confirmed) {
            router.post(route('provider-users.suspend', providerUser.id), {});
        }
    }

    function handleActivate() {
        router.post(route('provider-users.activate', providerUser.id), {});
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={providerUser.name}
                    backHref={route('provider-users.index')}
                    actions={
                        <div className="flex gap-2">
                            <Link
                                href={route('provider-users.edit', providerUser.id)}
                                className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                            >
                                Edit
                            </Link>
                            {providerUser.status === 'suspended' ? (
                                <button
                                    type="button"
                                    onClick={handleActivate}
                                    className="rounded-lg bg-green-600 px-3 py-2 text-sm font-medium text-white hover:bg-green-700"
                                >
                                    Activate
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={handleSuspend}
                                    className="rounded-lg bg-amber-500 px-3 py-2 text-sm font-medium text-white hover:bg-amber-600"
                                >
                                    Suspend
                                </button>
                            )}
                            <button
                                type="button"
                                onClick={handleDelete}
                                className="rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700"
                            >
                                Delete
                            </button>
                        </div>
                    }
                />
            }
        >
            <Head title={providerUser.name} />

            <div className="mx-auto max-w-3xl space-y-5">
                {/* Identity */}
                <section className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-gray-100 px-6 py-4 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Identity</h2>
                    </div>
                    <dl className="grid grid-cols-1 gap-5 px-6 py-5 sm:grid-cols-2">
                        <Field label="Name">{providerUser.name}</Field>
                        <Field label="Email">{providerUser.email ?? '—'}</Field>
                        <Field label="Username">
                            {providerUser.username
                                ? <span className="font-mono">{providerUser.username}</span>
                                : '—'}
                        </Field>
                        <Field label="Phone">{providerUser.phone_number ?? '—'}</Field>
                    </dl>
                </section>

                {/* Service */}
                <section className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-gray-100 px-6 py-4 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Service</h2>
                    </div>
                    <dl className="grid grid-cols-1 gap-5 px-6 py-5 sm:grid-cols-2">
                        <Field label="Service Type">
                            {providerUser.service_type
                                ? <>{providerUser.service_type.name_en} <span className="font-mono text-xs text-gray-400">({providerUser.service_type.code})</span></>
                                : '—'}
                        </Field>
                        <Field label="Provider">
                            {providerUser.provider
                                ? <>{providerUser.provider.name} <span className="font-mono text-xs text-gray-400">({providerUser.provider.code})</span></>
                                : '—'}
                        </Field>
                    </dl>
                </section>

                {/* Access */}
                <section className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-gray-100 px-6 py-4 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Access</h2>
                    </div>
                    <dl className="grid grid-cols-1 gap-5 px-6 py-5 sm:grid-cols-2">
                        <Field label="Status"><StatusBadge status={providerUser.status} /></Field>
                        <Field label="Portal">
                            <span className={providerUser.portal_enabled ? 'font-medium text-green-600 dark:text-green-400' : 'text-gray-400'}>
                                {providerUser.portal_enabled ? 'Enabled' : 'Disabled'}
                            </span>
                        </Field>
                        <Field label="Must Change Password">
                            {providerUser.must_change_password
                                ? <span className="font-medium text-amber-600 dark:text-amber-400">Yes</span>
                                : <span className="text-gray-400">No</span>}
                        </Field>
                        <Field label="Last Login"><LocalizedDateDisplay value={providerUser.last_login_at} withTime /></Field>
                        {providerUser.suspended_at && (
                            <Field label="Suspended At"><LocalizedDateDisplay value={providerUser.suspended_at} withTime /></Field>
                        )}
                        {providerUser.suspension_reason && (
                            <Field label="Suspension Reason">{providerUser.suspension_reason}</Field>
                        )}
                        <Field label="Created At"><LocalizedDateDisplay value={providerUser.created_at} withTime /></Field>
                    </dl>
                </section>

                {/* Reset Password */}
                <section id="reset-password" className="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-gray-100 px-6 py-4 dark:border-slate-800">
                        <h2 className="text-sm font-semibold text-gray-700 dark:text-slate-300">Reset Password</h2>
                    </div>
                    <ResetPasswordForm userId={providerUser.id} />
                </section>
            </div>
        </AuthenticatedLayout>
    );
}

function ResetPasswordForm({ userId }: { userId: string }) {
    const { confirm } = useConfirm();
    const inputCls = 'mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';

    async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        const data = new FormData(e.currentTarget);
        const password = data.get('password') as string;

        if (!password || password.length < 8) {
            alert('Password must be at least 8 characters.');
            return;
        }

        const { confirmed } = await confirm({
            title: 'Reset Password',
            description: 'The user will be required to change their password on next login.',
            confirmLabel: 'Reset',
            cancelLabel: 'Cancel',
            variant: 'danger',
        });

        if (confirmed) {
            router.post(route('provider-users.reset-password', userId), { password }, {
                onSuccess: () => (e.target as HTMLFormElement).reset(),
            });
        }
    }

    return (
        <form onSubmit={handleSubmit} className="px-6 py-5">
            <div className="max-w-sm">
                <label className="text-sm font-medium text-gray-700 dark:text-slate-300">
                    New Password
                </label>
                <input
                    name="password"
                    type="password"
                    className={inputCls}
                    placeholder="Min. 8 characters"
                    required
                    minLength={8}
                />
            </div>
            <div className="mt-4">
                <button type="submit" className="rounded-lg bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700">
                    Reset Password
                </button>
            </div>
        </form>
    );
}
