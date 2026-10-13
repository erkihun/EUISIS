import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Alert, Button, Card, FormField, Input, Select } from '@euisis/ui';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { FormEvent } from 'react';

type IdentityMode = 'anonymous' | 'identified' | 'optional';

type Settings = {
    enabled: boolean;
    identity_mode: IdentityMode;
    allow_attachments: boolean;
    feedback_enabled: boolean;
    suggestion_enabled: boolean;
    complaint_enabled: boolean;
    acknowledgement_enabled: boolean;
    rate_limit_per_source: number;
    rate_limit_per_ip: number;
};

type Props = { feedbackSettings: Settings; identityModes: IdentityMode[] };

const SWITCHES: Array<{ key: keyof Pick<Settings, 'enabled' | 'feedback_enabled' | 'suggestion_enabled' | 'complaint_enabled' | 'allow_attachments'>; label: string; hint: string }> = [
    { key: 'enabled', label: 'settingEnabled', hint: 'settingEnabledHint' },
    { key: 'feedback_enabled', label: 'settingFeedbackEnabled', hint: 'settingTypeHint' },
    { key: 'suggestion_enabled', label: 'settingSuggestionEnabled', hint: 'settingTypeHint' },
    { key: 'complaint_enabled', label: 'settingComplaintEnabled', hint: 'settingTypeHint' },
    { key: 'allow_attachments', label: 'settingAllowAttachments', hint: 'settingAllowAttachmentsHint' },
];

/**
 * The city-wide public feedback policy (brief §93). One editor for one
 * value: the group is kept out of the general System Settings page.
 */
export default function PublicFeedbackSettings({ feedbackSettings, identityModes }: Props) {
    const { t } = useLocale();
    const form = useForm<Settings>({ ...feedbackSettings });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(route('service-feedback.settings.update'), { preserveScroll: true });
    }

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('serviceFeedback.settingsTitle')} description={t('serviceFeedback.settingsIntro')} />}>
            <Head title={t('serviceFeedback.settingsTitle')} />

            <form onSubmit={submit} className="space-y-5">
                <Alert tone="info">{t('serviceFeedback.settingsCityWideNote')}</Alert>

                <Card className="space-y-5 p-5">
                    <h2 className="font-semibold">{t('serviceFeedback.settingsAvailability')}</h2>
                    <ul className="divide-y divide-[color:var(--app-border)]">
                        {SWITCHES.map((item) => (
                            <li key={item.key} className="flex items-start justify-between gap-4 py-3">
                                <label htmlFor={`setting-${item.key}`} className="text-sm">
                                    <span className="block font-medium text-[color:var(--app-foreground)]">{t(`serviceFeedback.${item.label}`)}</span>
                                    <span className="block text-[color:var(--app-muted-foreground)]">{t(`serviceFeedback.${item.hint}`)}</span>
                                    {errors[item.key] && <span role="alert" className="block text-red-600">{errors[item.key]}</span>}
                                </label>
                                <input id={`setting-${item.key}`} type="checkbox" role="switch" aria-checked={form.data[item.key]} checked={form.data[item.key]} onChange={(e) => form.setData(item.key, e.target.checked)} className="mt-1 h-5 w-5 shrink-0 rounded border-[color:var(--app-border-strong)]" />
                            </li>
                        ))}
                    </ul>
                </Card>

                <Card className="space-y-4 p-5">
                    <h2 className="font-semibold">{t('serviceFeedback.settingsIdentity')}</h2>
                    <FormField id="setting-identity-mode" label={t('serviceFeedback.settingIdentityMode')} description={t('serviceFeedback.settingIdentityModeHint')} error={errors.identity_mode} required>
                        {({ id, describedBy, invalid }) => (
                            <Select id={id} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.identity_mode} onChange={(e) => form.setData('identity_mode', e.target.value as IdentityMode)}>
                                {identityModes.map((mode) => <option key={mode} value={mode}>{t(`serviceFeedback.identityMode_${mode}`)}</option>)}
                            </Select>
                        )}
                    </FormField>
                    <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.settingIdentityPrivacyNote')}</p>
                    <label htmlFor="setting-acknowledgement" className="flex items-start justify-between gap-4 border-t border-[color:var(--app-border)] pt-4 text-sm">
                        <span>
                            <span className="block font-medium text-[color:var(--app-foreground)]">{t('serviceFeedback.settingAcknowledgement')}</span>
                            <span className="block text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.settingAcknowledgementHint')}</span>
                            {errors.acknowledgement_enabled && <span role="alert" className="block text-red-600">{errors.acknowledgement_enabled}</span>}
                        </span>
                        <input id="setting-acknowledgement" type="checkbox" role="switch" aria-checked={form.data.acknowledgement_enabled} checked={form.data.acknowledgement_enabled} onChange={(e) => form.setData('acknowledgement_enabled', e.target.checked)} className="mt-1 h-5 w-5 shrink-0 rounded border-[color:var(--app-border-strong)]" />
                    </label>
                </Card>

                <Card className="space-y-4 p-5">
                    <h2 className="font-semibold">{t('serviceFeedback.settingsAbuse')}</h2>
                    <p className="text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.settingsAbuseHint')}</p>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField id="setting-rate-source" label={t('serviceFeedback.settingRatePerSource')} error={errors.rate_limit_per_source} required>
                            {({ id, describedBy, invalid }) => <Input id={id} type="number" min={1} max={100} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.rate_limit_per_source} onChange={(e) => form.setData('rate_limit_per_source', Number(e.target.value))} />}
                        </FormField>
                        <FormField id="setting-rate-ip" label={t('serviceFeedback.settingRatePerIp')} error={errors.rate_limit_per_ip} required>
                            {({ id, describedBy, invalid }) => <Input id={id} type="number" min={1} max={500} aria-describedby={describedBy} aria-invalid={invalid} value={form.data.rate_limit_per_ip} onChange={(e) => form.setData('rate_limit_per_ip', Number(e.target.value))} />}
                        </FormField>
                    </div>
                </Card>

                <Card className="space-y-2 p-5 text-sm text-[color:var(--app-muted-foreground)]">
                    <h2 className="font-semibold text-[color:var(--app-foreground)]">{t('serviceFeedback.settingsNotDecided')}</h2>
                    <p>{t('serviceFeedback.settingsNotDecidedHint')}</p>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" variant="primary" loading={form.processing}>{t('common.save')}</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
