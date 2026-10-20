import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import SettingsCard from '@/Components/settings/SettingsCard';
import FieldRow, { ChoiceChips, Switch } from '@/Components/settings/FieldRow';
import { Plus, TrashIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Alert, Button, Input, Select } from '@euisis/ui';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

type TransferSettings = {
    require_same_position: boolean;
    require_same_grade: boolean;
    require_same_salary: boolean;
    allow_cross_institution: boolean;
    allow_exceptional_override: boolean;
    override_approver_roles: string[] | null;
    required_documents: string[] | null;
    minimum_service_months: number;
    releasing_consent_required: boolean;
    receiving_consent_required: boolean;
    final_approval_required: boolean;
    card_reprint_policy: string;
    service_recalculation_policy: string;
};

type BooleanKey = { [K in keyof TransferSettings]: TransferSettings[K] extends boolean ? K : never }[keyof TransferSettings];

/* Named to avoid shadowing the globally shared `settings` branding prop. */
type Props = { transferSettings: TransferSettings; roles?: string[]; can?: { update: boolean } };

/**
 * The rules every transfer follows. One page instead of tabs, so a refused
 * field is never hidden on a tab the administrator is not looking at.
 */
export default function TransferSettingsPage({ transferSettings, roles = [], can = { update: true } }: Props) {
    const { t } = useLocale();
    const form = useForm<TransferSettings>({
        ...transferSettings,
        override_approver_roles: transferSettings.override_approver_roles ?? [],
        required_documents: transferSettings.required_documents ?? [],
    });
    const { data, setData, errors, processing, isDirty } = form;
    const fieldError = (key: string) => (errors as Record<string, string | undefined>)[key];
    const documents = data.required_documents ?? [];
    const readOnly = !can.update;

    function submit(event: FormEvent) {
        event.preventDefault();
        form.transform((current) => ({ ...current, required_documents: (current.required_documents ?? []).map((name) => name.trim()).filter(Boolean) }));
        form.patch(route('transfer-settings.update'), { preserveScroll: true });
    }

    const toggle = (key: BooleanKey, description?: string) => (
        <FieldRow key={key} labelId={`${key}-label`} label={t(`transfers.setting.${key}`)} description={description ?? t(`transfers.settingHint.${key}`)} error={fieldError(key)} inline compact>
            <Switch id={key} checked={Boolean(data[key])} disabled={readOnly} labelledBy={`${key}-label`} onChange={(checked) => setData(key, checked)} />
        </FieldRow>
    );

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader backHref={route('transfers.dashboard')} title={t('transfers.settings')} description={t('transfers.settingsHint')} />}>
            <Head title={t('transfers.settings')} />

            <form onSubmit={submit} className="space-y-6">
                {Object.keys(errors).length > 0 && <Alert tone="danger">{t('transfers.fixErrorsBelow')}</Alert>}

                <SettingsCard title={t('transfers.tabRules')} description={t('transfers.rulesHint')}>
                    <div className="divide-y divide-(--app-border)">
                        {toggle('allow_cross_institution')}
                        {toggle('require_same_position')}
                        {toggle('require_same_grade')}
                        {toggle('require_same_salary')}
                        <FieldRow htmlFor="minimum_service_months" label={t('transfers.minimumServiceMonths')} description={t('transfers.settingHint.minimum_service_months')} error={errors.minimum_service_months}>
                            <Input id="minimum_service_months" type="number" inputMode="numeric" min={0} max={600} disabled={readOnly} className="max-w-40"
                                value={data.minimum_service_months} onChange={(e) => setData('minimum_service_months', Number(e.target.value))} />
                        </FieldRow>
                    </div>
                </SettingsCard>

                <SettingsCard title={t('transfers.approvalChain')} description={t('transfers.approvalChainHint')}>
                    <div className="divide-y divide-(--app-border)">
                        {toggle('releasing_consent_required')}
                        {toggle('receiving_consent_required')}
                        {toggle('final_approval_required')}
                    </div>
                </SettingsCard>

                <SettingsCard title={t('transfers.requiredDocuments')} description={t('transfers.settingsDocumentsHint')}>
                    <div className="space-y-3 px-5 py-4">
                        {documents.length === 0 && <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noRequiredDocumentsConfigured')}</p>}
                        {documents.map((document, index) => (
                            <div key={index} className="flex items-start gap-2">
                                <div className="min-w-0 flex-1">
                                    <label htmlFor={`settings-document-${index}`} className="sr-only">{`${t('transfers.documentName')} ${index + 1}`}</label>
                                    <Input id={`settings-document-${index}`} maxLength={100} disabled={readOnly} placeholder={t('transfers.documentNamePlaceholder')} value={document}
                                        aria-invalid={Boolean(fieldError(`required_documents.${index}`))}
                                        onChange={(e) => setData('required_documents', documents.map((value, i) => (i === index ? e.target.value : value)))} />
                                    {fieldError(`required_documents.${index}`) && <p role="alert" className="mt-1 text-xs text-red-600 dark:text-red-400">{fieldError(`required_documents.${index}`)}</p>}
                                </div>
                                <Button type="button" variant="ghost" size="icon" disabled={readOnly} aria-label={t('transfers.removeDocument')} title={t('transfers.removeDocument')}
                                    onClick={() => setData('required_documents', documents.filter((_, i) => i !== index))} icon={<TrashIcon className="size-4" />}>
                                </Button>
                            </div>
                        ))}
                        {!readOnly && (
                            <Button type="button" variant="outline" size="sm" onClick={() => setData('required_documents', [...documents, ''])} icon={<Plus className="size-4" />}>{t('transfers.addDocument')}
                            </Button>
                        )}
                    </div>
                </SettingsCard>

                <SettingsCard title={t('transfers.tabOverride')} description={t('transfers.overrideHint')}>
                    <div className="divide-y divide-(--app-border)">
                        {toggle('allow_exceptional_override')}
                        {data.allow_exceptional_override && (
                            <FieldRow labelId="override-roles-label" label={t('transfers.overrideApproverRoles')} description={t('transfers.overrideApproverRolesHint')} error={fieldError('override_approver_roles') ?? fieldError('override_approver_roles.0')}>
                                <ChoiceChips
                                    labelledBy="override-roles-label"
                                    disabled={readOnly}
                                    options={roles.map((role) => ({ value: role, label: role }))}
                                    value={data.override_approver_roles ?? []}
                                    onChange={(value) => setData('override_approver_roles', value)}
                                />
                            </FieldRow>
                        )}
                    </div>
                </SettingsCard>

                <SettingsCard title={t('transfers.tabPostTransfer')} description={t('transfers.postTransferHint')}>
                    <div className="divide-y divide-(--app-border)">
                        <FieldRow htmlFor="card_reprint_policy" label={t('transfers.cardReprintPolicy')} description={t('transfers.settingHint.card_reprint_policy')} error={errors.card_reprint_policy}>
                            <Select id="card_reprint_policy" disabled={readOnly} value={data.card_reprint_policy} onChange={(e) => setData('card_reprint_policy', e.target.value)}>
                                <option value="no_reprint">{t('transfers.cardReprintNoReprint')}</option>
                                <option value="request_reprint">{t('transfers.cardReprintRequestReprint')}</option>
                                <option value="auto_reprint">{t('transfers.cardReprintAutoReprint')}</option>
                            </Select>
                        </FieldRow>
                        <FieldRow htmlFor="service_recalculation_policy" label={t('transfers.serviceRecalculationPolicy')} description={t('transfers.settingHint.service_recalculation_policy')} error={errors.service_recalculation_policy}>
                            <Select id="service_recalculation_policy" disabled={readOnly} value={data.service_recalculation_policy} onChange={(e) => setData('service_recalculation_policy', e.target.value)}>
                                <option value="no_recalculation">{t('transfers.serviceRecalcNone')}</option>
                                <option value="recalculate_from_transfer">{t('transfers.serviceRecalcFromTransfer')}</option>
                                <option value="recalculate_from_effective_date">{t('transfers.serviceRecalcFromEffective')}</option>
                            </Select>
                        </FieldRow>
                    </div>
                </SettingsCard>

                {!readOnly && (
                    <div className="sticky bottom-0 z-10 -mx-1 flex items-center justify-end gap-3 border-t border-(--app-border) bg-(--app-background)/95 px-1 py-3 backdrop-blur-sm">
                        {isDirty && <span className="text-sm text-(--app-muted-foreground)" role="status">{t('transfers.unsavedChanges')}</span>}
                        <Button type="button" variant="outline" disabled={!isDirty || processing} onClick={() => form.reset()}>{t('transfers.discardChanges')}</Button>
                        <Button type="submit" variant="primary" loading={processing} disabled={!isDirty}>{t('common.save')}</Button>
                    </div>
                )}
            </form>
        </AuthenticatedLayout>
    );
}
