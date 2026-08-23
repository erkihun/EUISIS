import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

type Row = {
    id: string;
    name: string;
    code: string;
    location: string | null;
    status: string;
    daily_capacity: number | null;
    organization_assignments_count: number;
    users_count: number;
};

const inputCls =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

export default function Cafeterias({
    cafeterias,
    can,
}: {
    cafeterias: Row[];
    can: { manage: boolean };
}) {
    const { t } = useLocale();
    const [showForm, setShowForm] = useState(false);
    const form = useForm({ name: '', code: '', location: '', status: 'active', daily_capacity: '' });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/cafeterias', { onSuccess: () => setShowForm(false) });
    }

    return (
        <AppLayout title={t('cafeterias.title')}>
            {can.manage && (
                <div className="mb-4 flex justify-end">
                    <button
                        type="button"
                        onClick={() => setShowForm((value) => !value)}
                        className="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700"
                    >
                        {showForm ? 'Cancel' : 'New cafeteria'}
                    </button>
                </div>
            )}

            {showForm && can.manage && (
                <form onSubmit={submit} className="mb-6 space-y-3 rounded-2xl border border-gray-200 bg-white p-5">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <input className={inputCls} placeholder={t('common.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        <input className={inputCls} placeholder={t('common.code')} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                        <input className={inputCls} placeholder={t('extra.location')} value={form.data.location} onChange={(e) => form.setData('location', e.target.value)} />
                        <input className={inputCls} type="number" min={1} placeholder={t('extra.dailyCapacity')} value={form.data.daily_capacity} onChange={(e) => form.setData('daily_capacity', e.target.value)} />
                    </div>
                    {Object.keys(form.errors).length > 0 && (
                        <p className="text-xs text-red-600">{Object.values(form.errors).join(' ')}</p>
                    )}
                    <button type="submit" disabled={form.processing} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                        Save
                    </button>
                </form>
            )}

            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.cafeteria')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.location')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.organizations')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('extra.users')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.status')}</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {cafeterias.length === 0 ? (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">{t('cafeterias.noCafeterias')}</td></tr>
                        ) : cafeterias.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-2">
                                    <span className="font-medium text-slate-900">{row.name}</span>{' '}
                                    <span className="font-mono text-xs text-slate-400">{row.code}</span>
                                </td>
                                <td className="px-4 py-2 text-slate-600">{row.location ?? '-'}</td>
                                <td className="px-4 py-2 tabular-nums text-slate-700">{row.organization_assignments_count}</td>
                                <td className="px-4 py-2 tabular-nums text-slate-700">{row.users_count}</td>
                                <td className="px-4 py-2">
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                        row.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'
                                    }`}>{row.status}</span>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
