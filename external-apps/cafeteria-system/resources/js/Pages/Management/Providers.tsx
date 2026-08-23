import { FormEvent, useState } from 'react';
import { useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

type Provider = {
    id: string;
    code: string;
    name: string;
    contact_person: string | null;
    contact_phone: string | null;
    settlement_account: string | null;
    status: string;
    cafeterias_count: number;
    users_count: number;
};

const inputCls =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

export default function Providers({
    providers,
    can,
}: {
    providers: Provider[];
    can: { manage: boolean };
}) {
    const { t } = useLocale();
    const provider = providers[0] ?? null;
    const [editing, setEditing] = useState(false);

    const form = useForm({
        name: provider?.name ?? '',
        contact_person: provider?.contact_person ?? '',
        contact_phone: provider?.contact_phone ?? '',
        settlement_account: provider?.settlement_account ?? '',
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.patch('/providers', { onSuccess: () => setEditing(false) });
    }

    if (!provider) {
        return (
            <AppLayout title={t('providers.title')}>
                <p className="rounded-2xl border border-dashed border-gray-300 bg-white p-8 text-center text-sm text-slate-500">
                    No provider is linked to your account.
                </p>
            </AppLayout>
        );
    }

    return (
        <AppLayout title={t('providers.title')}>
            <section className="rounded-2xl border border-gray-200 bg-white p-5">
                <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-bold text-slate-900">{provider.name}</h2>
                        <p className="font-mono text-xs text-slate-500">{provider.code}</p>
                    </div>
                    {can.manage && (
                        <button
                            type="button"
                            onClick={() => setEditing((value) => !value)}
                            className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-gray-50"
                        >
                            {editing ? 'Cancel' : 'Edit'}
                        </button>
                    )}
                </div>

                {editing && can.manage ? (
                    <form onSubmit={submit} className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                            <input className={inputCls} placeholder={t('common.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            <input className={inputCls} placeholder={t('extra.contactPerson')} value={form.data.contact_person} onChange={(e) => form.setData('contact_person', e.target.value)} />
                            <input className={inputCls} placeholder={t('extra.contactPhone')} value={form.data.contact_phone} onChange={(e) => form.setData('contact_phone', e.target.value)} />
                            <input className={inputCls} placeholder={t('extra.settlementAccount')} value={form.data.settlement_account} onChange={(e) => form.setData('settlement_account', e.target.value)} />
                        </div>
                        <button type="submit" disabled={form.processing} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                            Save
                        </button>
                    </form>
                ) : (
                    <dl className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {[
                            ['Contact person', provider.contact_person],
                            ['Contact phone', provider.contact_phone],
                            ['Settlement account', provider.settlement_account],
                            ['Status', provider.status],
                        ].map(([label, value]) => (
                            <div key={label as string}>
                                <dt className="text-xs text-slate-500">{label}</dt>
                                <dd className="mt-1 text-sm font-medium text-slate-900">{(value as string) ?? '-'}</dd>
                            </div>
                        ))}
                        <div>
                            <dt className="text-xs text-slate-500">{t('extra.cafeterias')}</dt>
                            <dd className="mt-1 text-sm font-bold tabular-nums text-slate-900">{provider.cafeterias_count}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">{t('extra.users')}</dt>
                            <dd className="mt-1 text-sm font-bold tabular-nums text-slate-900">{provider.users_count}</dd>
                        </div>
                    </dl>
                )}
            </section>
        </AppLayout>
    );
}
