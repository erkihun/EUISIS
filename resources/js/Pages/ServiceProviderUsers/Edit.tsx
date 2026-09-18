import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type ServiceType = { id: string; code: string; name_en: string };
type ProviderUserData = {
    id: string;
    name: string;
    email: string | null;
    username: string | null;
    phone_number: string | null;
    status: string;
    portal_enabled: boolean;
    must_change_password: boolean;
    service_type_id: string | null;
};

const inputCls = 'mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100';
const sectionCls = 'border-b border-gray-200 px-6 py-5 dark:border-slate-800';

export default function EditProviderUser({
    providerUser,
    serviceTypes,
}: {
    providerUser: ProviderUserData;
    serviceTypes: ServiceType[];
}) {
    const form = useForm({
        service_type_id:      providerUser.service_type_id ?? '',
        name:                 providerUser.name,
        email:                providerUser.email ?? '',
        username:             providerUser.username ?? '',
        phone_number:         providerUser.phone_number ?? '',
        status:               providerUser.status,
        portal_enabled:       providerUser.portal_enabled,
        must_change_password: providerUser.must_change_password,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.patch(route('provider-users.update', providerUser.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={`Edit — ${providerUser.name}`}
                    backHref={route('provider-users.show', providerUser.id)}
                />
            }
        >
            <Head title={`Edit — ${providerUser.name}`} />

            <form onSubmit={submit} className="mx-auto max-w-2xl overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">

                {/* Service type */}
                <div className={sectionCls}>
                    <h3 className="mb-4 text-sm font-semibold text-gray-700 dark:text-slate-300">Service type</h3>
                    <div>
                        <InputLabel htmlFor="service_type_id" value="Service Type" />
                        <select
                            id="service_type_id"
                            className={inputCls}
                            value={form.data.service_type_id}
                            onChange={e => form.setData('service_type_id', e.target.value)}
                            required
                        >
                            <option value="">Select service type</option>
                            {serviceTypes.map(st => (
                                <option key={st.id} value={st.id}>{st.name_en} — {st.code}</option>
                            ))}
                        </select>
                        <InputError message={form.errors.service_type_id} className="mt-1" />
                    </div>
                </div>

                {/* Identity */}
                <div className={sectionCls}>
                    <h3 className="mb-4 text-sm font-semibold text-gray-700 dark:text-slate-300">Identity</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <InputLabel htmlFor="name" value="Name" />
                            <input
                                id="name"
                                className={inputCls}
                                type="text"
                                value={form.data.name}
                                onChange={e => form.setData('name', e.target.value)}
                                required
                            />
                            <InputError message={form.errors.name} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="email" value="Email" />
                            <input
                                id="email"
                                className={inputCls}
                                type="email"
                                value={form.data.email}
                                onChange={e => form.setData('email', e.target.value)}
                            />
                            <InputError message={form.errors.email} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="username" value="Username" />
                            <input
                                id="username"
                                className={inputCls}
                                type="text"
                                value={form.data.username}
                                onChange={e => form.setData('username', e.target.value)}
                            />
                            <InputError message={form.errors.username} className="mt-1" />
                        </div>

                        <div>
                            <InputLabel htmlFor="phone_number" value="Phone" />
                            <input
                                id="phone_number"
                                className={inputCls}
                                type="text"
                                value={form.data.phone_number}
                                onChange={e => form.setData('phone_number', e.target.value)}
                            />
                            <InputError message={form.errors.phone_number} className="mt-1" />
                        </div>
                    </div>
                </div>

                {/* Access */}
                <div className={sectionCls}>
                    <h3 className="mb-4 text-sm font-semibold text-gray-700 dark:text-slate-300">Access</h3>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <InputLabel htmlFor="status" value="Status" />
                            <select
                                id="status"
                                className={inputCls}
                                value={form.data.status}
                                onChange={e => form.setData('status', e.target.value)}
                            >
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                                <option value="suspended">Suspended</option>
                            </select>
                            <InputError message={form.errors.status} className="mt-1" />
                        </div>

                        <label className="flex cursor-pointer items-center gap-2 pt-6 text-sm text-gray-700 dark:text-slate-300">
                            <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-gray-300 text-[color:var(--color-primary)]"
                                checked={form.data.portal_enabled}
                                onChange={e => form.setData('portal_enabled', e.target.checked)}
                            />
                            Portal enabled
                        </label>

                        <label className="flex cursor-pointer items-center gap-2 pt-6 text-sm text-gray-700 dark:text-slate-300">
                            <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-gray-300 text-[color:var(--color-primary)]"
                                checked={form.data.must_change_password}
                                onChange={e => form.setData('must_change_password', e.target.checked)}
                            />
                            Must change password
                        </label>
                    </div>
                </div>

                {/* Footer */}
                <div className="flex items-center justify-end gap-3 px-6 py-4">
                    <a
                        href={route('provider-users.show', providerUser.id)}
                        className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                    >
                        Cancel
                    </a>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60"
                    >
                        {form.processing ? 'Saving…' : 'Save Changes'}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
