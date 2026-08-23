import { FormEvent, useState } from 'react';
import { router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { useLocale } from '@/hooks/useLocale';

type Field = {
    key: string;
    label: string;
    type: 'string' | 'boolean' | 'decimal' | 'integer' | 'select';
    options: string[] | null;
    value: string;
};

type ProviderUser = {
    id: string;
    name: string;
    email: string;
    role: string;
    status: string;
    last_login_at: string | null;
    cafeteria: { id: string; name: string; code: string } | null;
};

const TAB_LABELS: Record<string, string> = {
    general: 'General Settings',
    subsidy: 'Subsidy Settings',
    days: 'Working Days',
    scan: 'Scan Rules',
    'day-rules': 'Working Day Rules',
    holidays: 'Public Holidays',
    'subsidy-rules': 'Subsidy Rules',
    reports: 'Report Settings',
    'provider-users': 'Provider Users',
};

const inputCls =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 disabled:bg-gray-50 disabled:text-slate-400';

export default function CafeteriaSettings({
    groups,
    tabs,
    providerUsers,
    can,
}: {
    groups: Record<string, Field[]>;
    tabs: string[];
    providerUsers: ProviderUser[];
    can: { manage: boolean };
}) {
    const { t } = useLocale();
    const [activeTab, setActiveTab] = useState(tabs[0] ?? 'general');
    const [values, setValues] = useState<Record<string, string>>(() => {
        const seed: Record<string, string> = {};

        Object.values(groups).flat().forEach((field) => {
            seed[field.key] = field.value;
        });

        return seed;
    });
    const [saving, setSaving] = useState(false);

    function setValue(key: string, value: string) {
        setValues((current) => ({ ...current, [key]: value }));
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setSaving(true);

        // Only the fields on the active tab are submitted, so one tab's edits
        // can never silently overwrite another's.
        const payload = (groups[activeTab] ?? []).map((field) => ({
            key: field.key,
            value: values[field.key] ?? '',
        }));

        router.patch('/cafeteria-settings', { settings: payload }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    }

    function renderField(field: Field) {
        const value = values[field.key] ?? '';

        if (field.type === 'boolean') {
            const checked = value === 'true' || value === '1';

            return (
                <label key={field.key} className="flex items-center gap-2 py-1.5">
                    <input
                        type="checkbox"
                        checked={checked}
                        disabled={!can.manage}
                        onChange={(e) => setValue(field.key, e.target.checked ? 'true' : 'false')}
                        className="h-4 w-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                    />
                    <span className="text-sm text-slate-700">{field.label}</span>
                    <span className="ml-auto font-mono text-[10px] text-slate-400">{field.key}</span>
                </label>
            );
        }

        return (
            <label key={field.key} className="block">
                <span className="mb-1 block text-sm font-medium text-slate-700">{field.label}</span>

                {field.type === 'select' && field.options ? (
                    <select
                        className={inputCls}
                        value={value}
                        disabled={!can.manage}
                        onChange={(e) => setValue(field.key, e.target.value)}
                    >
                        {field.options.map((option) => (
                            <option key={option} value={option}>{option.replace(/_/g, ' ')}</option>
                        ))}
                    </select>
                ) : (
                    <input
                        className={inputCls}
                        type={field.type === 'decimal' || field.type === 'integer' ? 'number' : 'text'}
                        step={field.type === 'decimal' ? '0.01' : undefined}
                        value={value}
                        disabled={!can.manage}
                        onChange={(e) => setValue(field.key, e.target.value)}
                    />
                )}

                <span className="mt-1 block font-mono text-[10px] text-slate-400">{field.key}</span>
            </label>
        );
    }

    return (
        <AppLayout title={t('settings.title')}>
            {/* Tab strip */}
            <nav className="mb-6 flex w-full overflow-x-auto border-b border-gray-200" aria-label="Settings sections">
                {tabs.map((tab) => {
                    const active = tab === activeTab;

                    return (
                        <button
                            key={tab}
                            type="button"
                            onClick={() => setActiveTab(tab)}
                            aria-current={active ? 'page' : undefined}
                            className={[
                                'shrink-0 whitespace-nowrap border-b-2 px-5 py-3 text-sm font-medium transition-colors',
                                active
                                    ? 'border-emerald-600 text-emerald-700'
                                    : 'border-transparent text-slate-600 hover:border-gray-300 hover:text-slate-900',
                            ].join(' ')}
                        >
                            {TAB_LABELS[tab] ?? tab}
                        </button>
                    );
                })}
            </nav>

            {activeTab === 'provider-users' ? (
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
                            {providerUsers.length === 0 ? (
                                <tr><td colSpan={5} className="px-4 py-8 text-center text-slate-500">{t('extra.noProviderUsers')}</td></tr>
                            ) : providerUsers.map((row) => (
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
            ) : (
                <form onSubmit={submit}>
                    <section className="rounded-2xl border border-gray-200 bg-white p-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            {(groups[activeTab] ?? []).map(renderField)}
                        </div>

                        {can.manage && (
                            <div className="mt-5 border-t border-gray-100 pt-4">
                                <button
                                    type="submit"
                                    disabled={saving}
                                    className="rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                                >
                                    {saving ? 'Saving...' : 'Save settings'}
                                </button>
                            </div>
                        )}
                    </section>
                </form>
            )}
        </AppLayout>
    );
}
