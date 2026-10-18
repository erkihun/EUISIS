import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, JSX } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import SettingField from '@/Components/settings/SettingField';
import { buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { tr } from '@/lib/communication/format';
import type { SettingsField } from '@/lib/settings';
import type { PageProps } from '@/types';

type Metrics = {
    messages_24h: number;
    active_conversations_24h: number;
    live_announcements: number;
    open_reports: number;
    pending_access_requests: number;
    active_access_grants: number;
    realtime: { driver: string; enabled: boolean; queue: string; waiting: number | null; failures: number };
};

type Props = PageProps<{ fields: SettingsField[]; metrics: Metrics }>;
type Value = string | number | boolean | string[] | File | null;

const SECTIONS: [string, string[]][] = [
    ['access', ['communication_enabled', 'communication_scope_default']],
    ['groups', ['allow_employee_groups', 'allow_employee_channels']],
    ['messages', ['allow_message_editing', 'message_edit_window_minutes', 'allow_message_deletion', 'message_delete_window_minutes', 'allow_message_forwarding', 'allow_read_receipts']],
    ['presence', ['allow_presence', 'allow_last_seen']],
    ['files', ['max_attachment_size_kb', 'allowed_attachment_types', 'enable_attachment_scanning']],
    ['announcements', ['email_urgent_announcements', 'sms_emergency_announcements']],
    ['retention', ['retention_days', 'announcement_retention_days']],
    ['investigations', ['break_glass_max_hours']],
];

export default function CommunicationSettings({ fields, metrics }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const byKey = new Map(fields.map((f) => [f.key, f]));
    const form = useForm<Record<string, Value>>(Object.fromEntries(fields.map((f) => [f.key, (f.value ?? null) as Value])));
    const errors = form.errors as Record<string, string | undefined>;
    const placed = new Set(SECTIONS.flatMap(([, keys]) => keys));
    const sections: [string, string[]][] = [...SECTIONS, ...(fields.some((f) => !placed.has(f.key)) ? [['other', fields.filter((f) => !placed.has(f.key)).map((f) => f.key)] as [string, string[]]] : [])];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(route('communication.settings.update'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    const rt = metrics.realtime;
    const status: [string, string][] = [
        [t('communication.settings.status.messages24h'), metrics.messages_24h.toLocaleString()],
        [t('communication.settings.status.activeConversations'), metrics.active_conversations_24h.toLocaleString()],
        [t('communication.settings.status.liveAnnouncements'), metrics.live_announcements.toLocaleString()],
        [t('communication.settings.status.openReports'), metrics.open_reports.toLocaleString()],
        [t('communication.settings.status.pendingRequests'), metrics.pending_access_requests.toLocaleString()],
        [t('communication.settings.status.activeGrants'), metrics.active_access_grants.toLocaleString()],
    ];

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('communication.settings.title')} description={t('communication.settings.description')} />}>
            <Head title={t('communication.settings.title')} />
            <div className="space-y-6">
                <section aria-labelledby="comm-status" className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                    <h2 id="comm-status" className="text-sm font-semibold text-[color:var(--app-foreground)]">{t('communication.settings.status.title')}</h2>
                    <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-3">
                        {status.map(([label, value]) => (
                            <div key={label}>
                                <dt className="text-[11px] text-[color:var(--app-muted-foreground)]">{label}</dt>
                                <dd className="text-base font-semibold tabular-nums text-[color:var(--app-foreground)]">{value}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="mt-4 border-t border-[color:var(--app-border)] pt-3 text-sm text-[color:var(--app-foreground)]">
                        <span className="font-medium">{t('communication.settings.status.realtime')}: </span>
                        {rt.enabled ? tr(t('communication.settings.status.realtimeOn'), { driver: rt.driver, queue: rt.queue }) : t('communication.settings.status.realtimeOff')}
                        {rt.enabled && rt.waiting !== null && <span className="text-[color:var(--app-muted-foreground)]"> · {tr(t('communication.settings.status.waiting'), { n: rt.waiting })}</span>}
                        {rt.failures > 0 && <span className="text-[color:var(--color-danger,#b42318)]"> · {tr(t('communication.settings.status.failures'), { n: rt.failures })}</span>}
                    </p>
                </section>

                <form onSubmit={submit} className="space-y-6">
                    {sections.map(([section, keys]) => {
                        const items = keys.map((k) => byKey.get(k)).filter((f): f is SettingsField => f !== undefined);
                        if (items.length === 0) return null;
                        return (
                            <section key={section} className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
                                <h2 className="text-sm font-semibold text-[color:var(--app-foreground)]">{section === 'other' ? '—' : t(`communication.settings.sections.${section}`)}</h2>
                                {section === 'retention' && <p className="mt-1 text-xs text-[color:var(--app-muted-foreground)]">{t('communication.settings.retentionHelp')}</p>}
                                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                    {items.map((field) => (
                                        <div key={field.key} className={field.type === 'multiselect' ? 'sm:col-span-2' : ''}>
                                            <SettingField field={field} locale={locale === 'am' ? 'am' : 'en'} value={form.data[field.key] ?? null} error={errors[field.key]}
                                                disabled={form.processing} onChange={(value) => form.setData(field.key, value)} />
                                        </div>
                                    ))}
                                </div>
                            </section>
                        );
                    })}
                    <div className="sticky bottom-0 flex items-center justify-end gap-3 border-t border-[color:var(--app-border)] bg-[color:var(--app-background)]/95 py-3 backdrop-blur">
                        <span role="status" className="text-sm text-emerald-700 dark:text-emerald-400">{form.recentlySuccessful ? t('communication.settings.saved') : ''}</span>
                        <button type="submit" disabled={form.processing || !form.isDirty} className={buttonClassName({ variant: 'primary' })}>{t('communication.settings.save')}</button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
