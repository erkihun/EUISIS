import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

type Row = {
    id: string;
    name: string;
    email: string;
    role: string;
    status: string;
    last_login_at: string | null;
    cafeteria: { id: string; name: string; code: string } | null;
};

type CafeteriaOption = { id: string; name: string; code: string };

const inputCls =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

export default function Users({
    users,
    cafeterias,
    roles,
    can,
}: {
    users: Row[];
    cafeterias: CafeteriaOption[];
    roles: string[];
    can: { manage: boolean };
}) {
    const { t } = useLocale();
    const [showForm, setShowForm] = useState(false);
    const form = useForm({
        name: '', email: '', password: '',
        role: roles[0] ?? 'scanner',
        cafeteria_id: '', status: 'active',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/users', { onSuccess: () => setShowForm(false) });
    }

    return (
        <AppLayout title={t('users.title')}>
            {can.manage && (
                <div className="mb-4 flex justify-end">
                    <button
                        type="button"
                        onClick={() => setShowForm((value) => !value)}
                        className="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700"
                    >
                        {showForm ? 'Cancel' : 'New user'}
                    </button>
                </div>
            )}

            {showForm && can.manage && (
                <form onSubmit={submit} className="mb-6 space-y-3 rounded-2xl border border-gray-200 bg-white p-5">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <input className={inputCls} placeholder={t('common.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <input className={inputCls} type="email" placeholder={t('common.email')} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        <input className={inputCls} type="password" placeholder={t('users.password')} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} />
                        <select className={inputCls} value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                            {roles.map((role) => (
                                <option key={role} value={role}>{role.replace(/_/g, ' ')}</option>
                            ))}
                        </select>
                        <select className={inputCls} value={form.data.cafeteria_id} onChange={(e) => form.setData('cafeteria_id', e.target.value)}>
                            <option value="">No specific cafeteria (provider admin)</option>
                            {cafeterias.map((c) => (
                                <option key={c.id} value={c.id}>{c.name} ({c.code})</option>
                            ))}
                        </select>
                    </div>
                    {Object.keys(form.errors).length > 0 && (
                        <p className="text-xs text-red-600">{Object.values(form.errors).join(' ')}</p>
                    )}
                    <button type="submit" disabled={form.processing} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                        Save user
                    </button>
                </form>
            )}

            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.user')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.role')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.cafeteria')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.status')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.lastLogin')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {users.length === 0 ? (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">{t('users.noUsers')}</td></tr>
                        ) : users.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-2">
                                    <span className="font-medium text-slate-900">{row.name}</span>
                                    <span className="block text-xs text-slate-400">{row.email}</span>
                                </td>
                                <td className="px-4 py-2 text-slate-600">{row.role.replace(/_/g, ' ')}</td>
                                <td className="px-4 py-2 text-slate-600">{row.cafeteria?.name ?? 'All (provider)'}</td>
                                <td className="px-4 py-2">
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                        row.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'
                                    }`}>{row.status}</span>
                                </td>
                                <td className="px-4 py-2 text-xs text-slate-500">{row.last_login_at ?? '-'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
