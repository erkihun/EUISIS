import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { Alert, Button, Card, Input, Select } from '@euisis/ui';
import type { FormEvent } from 'react';
import { useTransportLabels } from '@/Components/transport/labels';

type Value = boolean | number | string;
type Settings = Record<string, Value>;

/** Sections mirror TransportSettings::DEFAULTS; a key the server adds later still renders, in "other". */
const SECTIONS: { id: string; keys: string[] }[] = [
    { id: 'eligibility', keys: ['require_pass_for_scan', 'allow_pay_as_you_go', 'organization_grants_board_without_pass', 'alternative_route_policy'] },
    { id: 'scanning', keys: ['qr_enabled', 'nfc_enabled', 'scan_nonce_required', 'require_trip_for_boarding', 'duplicate_boarding_policy', 'capacity_enforcement'] },
    { id: 'exceptions', keys: ['manual_exception_enabled', 'manual_exceptions_alert_threshold'] },
    { id: 'devices', keys: ['offline_enabled', 'offline_grace_minutes', 'device_inactivity_timeout_minutes'] },
    { id: 'finance', keys: ['settlement_requires_distinct_approver', 'gps_retention_days'] },
];

export default function Index({ transportSettings, choices }: { transportSettings: Settings; choices: Record<string, string[]> }) {
    const { t, label } = useTransportLabels();
    const form = useForm<Settings>({ ...transportSettings });
    const known = new Set(SECTIONS.flatMap((s) => s.keys));
    const sections = [...SECTIONS, { id: 'other', keys: Object.keys(transportSettings).filter((k) => !known.has(k)) }].filter((s) => s.keys.length > 0);

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(route('transport.settings.update'), { preserveScroll: true });
    }

    function control(key: string) {
        const value = form.data[key];
        if (typeof value === 'boolean') return <input type="checkbox" role="switch" className="mt-1 h-5 w-5 shrink-0" checked={value} onChange={(e) => form.setData(key, e.target.checked)} />;
        if (choices[key]) return <Select className="w-full sm:w-72" value={String(value)} onChange={(e) => form.setData(key, e.target.value)}>{choices[key].map((c) => <option key={c} value={c}>{t(`transport.settingChoices.${key}.${c}`)}</option>)}</Select>;
        return <Input type="number" min={0} className="w-32 text-right tabular-nums" value={String(value)} onChange={(e) => form.setData(key, e.target.value === '' ? '' : Number(e.target.value))} />;
    }

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('transport.settings')} description={t('transport.settingsIntro')} />}>
            <Head title={t('transport.settings')} />
            <form onSubmit={submit} className="space-y-4">
                <Alert tone="info">{t('transport.settingsPolicyNote')}</Alert>
                {sections.map((section) => (
                    <Card key={section.id} className="p-0">
                        <h2 className="border-b border-(--app-border) px-4 py-3 text-sm font-semibold">{t(`transport.settingSections.${section.id}`)}</h2>
                        <div className="divide-y divide-(--app-border)">
                            {section.keys.map((key) => (
                                <label key={key} className="flex cursor-pointer flex-col gap-2 p-4 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium">{label('settingLabels', key)}</span>
                                        <span className="mt-0.5 block text-sm text-(--app-muted-foreground)">{label('settingHelp', key)}</span>
                                        {form.errors[key] && <span className="mt-1 block text-sm text-red-600">{form.errors[key]}</span>}
                                    </span>
                                    {control(key)}
                                </label>
                            ))}
                        </div>
                    </Card>
                ))}
                <div className="flex justify-end">
                    <Button type="submit" variant="primary" disabled={form.processing || !form.isDirty}>{t('transport.save')}</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
