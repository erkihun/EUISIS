import { FormEvent, useEffect, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

type EuisisOrganization = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    status: string;
    type: { code: string; name_en: string } | null;
};

type Assignment = {
    id: string;
    organization_code: string;
    organization_name_snapshot: string;
    status: string;
    effective_from: string;
    effective_to: string | null;
    cafeteria: { id: string; name: string; code: string } | null;
};

type CafeteriaOption = { id: string; name: string; code: string };

const inputCls =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500';

export default function Assignments({
    assignments,
    cafeterias,
    can,
}: {
    assignments: Assignment[];
    cafeterias: CafeteriaOption[];
    can: { manage: boolean };
}) {
    const { t } = useLocale();
    const [showForm, setShowForm] = useState(false);
    const form = useForm({
        cafeteria_id: cafeterias[0]?.id ?? '',
        organization_code: '',
        organization_name_snapshot: '',
        organization_type_snapshot: '',
        source_system_organization_id: '',
        status: 'active',
        effective_from: new Date().toISOString().slice(0, 10),
        effective_to: '',
    });

    // ── EUISIS organization directory ───────────────────────────────────
    const [organizations, setOrganizations] = useState<EuisisOrganization[]>([]);
    const [orgSearch, setOrgSearch] = useState('');
    const [orgLoading, setOrgLoading] = useState(false);
    const [orgError, setOrgError] = useState<string | null>(null);
    const [manualEntry, setManualEntry] = useState(false);

    // Debounced lookup so typing does not fire a request per keystroke.
    useEffect(() => {
        if (!showForm) {
            return;
        }

        setOrgLoading(true);
        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch(`/assignments/organization-lookup?search=${encodeURIComponent(orgSearch)}`, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();

                setOrganizations(payload.organizations ?? []);
                setOrgError(payload.error ?? null);
            } catch {
                setOrgError('lookup_failed');
                setOrganizations([]);
            } finally {
                setOrgLoading(false);
            }
        }, 300);

        return () => window.clearTimeout(timer);
    }, [orgSearch, showForm]);

    /** Copy the chosen organization into the form as a snapshot. */
    function chooseOrganization(code: string) {
        const organization = organizations.find((item) => item.code === code);

        form.setData({
            ...form.data,
            organization_code: organization?.code ?? '',
            organization_name_snapshot: organization?.name_en ?? '',
            organization_type_snapshot: organization?.type?.name_en ?? '',
            source_system_organization_id: organization?.id ?? '',
        });
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/assignments', { onSuccess: () => setShowForm(false) });
    }

    return (
        <AppLayout title={t('assignments.title')}>
            <p className="mb-4 text-sm text-slate-500">
                A cafeteria may serve only employees whose organization is assigned to it and
                whose assignment is in force today.
            </p>

            {can.manage && (
                <div className="mb-4 flex justify-end">
                    <button
                        type="button"
                        onClick={() => setShowForm((value) => !value)}
                        className="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700"
                    >
                        {showForm ? t('common.cancel') : t('assignments.assign')}
                    </button>
                </div>
            )}

            {showForm && can.manage && (
                <form onSubmit={submit} className="mb-6 space-y-3 rounded-2xl border border-gray-200 bg-white p-5">
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <select className={inputCls} value={form.data.cafeteria_id} onChange={(e) => form.setData('cafeteria_id', e.target.value)}>
                            {cafeterias.map((c) => (
                                <option key={c.id} value={c.id}>{c.name} ({c.code})</option>
                            ))}
                        </select>
                        {/* Organization is picked from the live EUISIS directory. */}
                        <div className="sm:col-span-2">
                            <input
                                className={`${inputCls} mb-2`}
                                placeholder={t('assignments.searchEuisis')}
                                value={orgSearch}
                                onChange={(e) => setOrgSearch(e.target.value)}
                            />

                            {manualEntry ? (
                                <div className="grid gap-2 sm:grid-cols-2">
                                    <input className={inputCls} placeholder={t('assignments.organizationCode')} value={form.data.organization_code} onChange={(e) => form.setData('organization_code', e.target.value)} />
                                    <input className={inputCls} placeholder={t('assignments.organizationName')} value={form.data.organization_name_snapshot} onChange={(e) => form.setData('organization_name_snapshot', e.target.value)} />
                                </div>
                            ) : (
                                <select
                                    className={inputCls}
                                    value={form.data.organization_code}
                                    onChange={(e) => chooseOrganization(e.target.value)}
                                >
                                    <option value="">
                                        {orgLoading ? t('assignments.loadingOrganizations') : t('assignments.selectOrganization')}
                                    </option>
                                    {organizations.map((organization) => (
                                        <option key={organization.id} value={organization.code}>
                                            {organization.code} — {organization.name_en}
                                            {organization.type ? ` (${organization.type.name_en})` : ''}
                                        </option>
                                    ))}
                                </select>
                            )}

                            <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                                {orgError && (
                                    <span className="text-amber-700">
                                        EUISIS directory unavailable ({orgError}) — enter the code manually.
                                    </span>
                                )}
                                {!orgError && !orgLoading && organizations.length === 0 && (
                                    <span className="text-slate-500">{t('assignments.noOrganizationsReturned')}</span>
                                )}
                                <button
                                    type="button"
                                    onClick={() => setManualEntry((value) => !value)}
                                    className="ml-auto font-medium text-emerald-700 hover:underline"
                                >
                                    {manualEntry ? t('assignments.pickFromEuisis') : t('assignments.enterManually')}
                                </button>
                            </div>
                        </div>
                        <input className={inputCls} type="date" value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} />
                        <input className={inputCls} type="date" placeholder={t('extra.effectiveToPlaceholder')} value={form.data.effective_to} onChange={(e) => form.setData('effective_to', e.target.value)} />
                    </div>
                    {Object.keys(form.errors).length > 0 && (
                        <p className="text-xs text-red-600">{Object.values(form.errors).join(' ')}</p>
                    )}
                    <button type="submit" disabled={form.processing} className="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                        Save assignment
                    </button>
                </form>
            )}

            <section className="overflow-x-auto rounded-2xl border border-gray-200 bg-white">
                <table className="min-w-full text-left text-sm">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.organization')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('assignments.cafeteria')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('assignments.effective')}</th>
                            <th className="px-4 py-2 font-medium text-slate-600">{t('common.status')}</th>
                            <th className="px-4 py-2" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {assignments.length === 0 ? (
                            <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">{t('assignments.noAssignments')}</td></tr>
                        ) : assignments.map((row) => (
                            <tr key={row.id}>
                                <td className="px-4 py-2">
                                    <span className="font-medium text-slate-900">{row.organization_name_snapshot}</span>{' '}
                                    <span className="font-mono text-xs text-slate-400">{row.organization_code}</span>
                                </td>
                                <td className="px-4 py-2 text-slate-600">{row.cafeteria?.name ?? '-'}</td>
                                <td className="px-4 py-2 text-xs text-slate-500">
                                    {row.effective_from} &rarr; {row.effective_to ?? 'open'}
                                </td>
                                <td className="px-4 py-2">
                                    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                        row.status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'
                                    }`}>{row.status}</span>
                                </td>
                                <td className="px-4 py-2 text-right">
                                    {can.manage && (
                                        <button
                                            type="button"
                                            onClick={() => router.delete(`/assignments/${row.id}`)}
                                            className="text-xs font-medium text-red-600 hover:underline"
                                        >
                                            Remove
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </section>
        </AppLayout>
    );
}
