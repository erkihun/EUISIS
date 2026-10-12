import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Alert, Button, Card, FormField, Select } from '@euisis/ui';
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
};

type Props = { settings: Settings; identityModes: IdentityMode[] };

const SWITCHES: Array<{ key: keyof Omit<Settings, 'identity_mode'>; label: string; hint: string }> = [
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
export default function PublicFeedbackSettings({ settings, identityModes }: Props) {
    const { t } = useLocale();
    const form = useForm<Settings>({ ...settings });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.put(route('service-feedback.settings.update'), { preserveScroll: true });
    }

    const errors = form.errors as Record<string, string | undefined>;

    return (
        <AuthenticatedLayout header={<PageHeader title={t('serviceFeedback.settingsTitle')} description={t('serviceFeedback.settingsIntro')} />}>
            <Head title={t('serviceFeedback.settingsTitle')} />

            <form onSubmit={submit} className="mx-auto max-w-3xl space-y-5">
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
