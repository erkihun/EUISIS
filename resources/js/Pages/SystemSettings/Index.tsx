import Button from '@/Components/Button';
import PageHeader from '@/Components/PageHeader';
import SettingField from '@/Components/settings/SettingField';
import SettingsCard from '@/Components/settings/SettingsCard';
import SettingsSection from '@/Components/settings/SettingsSection';
import SettingsTabs from '@/Components/settings/SettingsTabs';
import TestChannelButton from '@/Components/settings/TestChannelButton';
import { resolveIdCardTemplate } from '@/Components/IdCards/idCardTemplates';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useLocale } from '@/hooks/useLocale';
import type { SettingsField, SettingsGroupPayload } from '@/lib/settings';
import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState, type FormEvent } from 'react';

type SettingsCan = {
    view: boolean;
    update: boolean;
    manageGeneral: boolean;
    manageLocalization: boolean;
    manageNotifications: boolean;
    manageEmail: boolean;
    manageSms: boolean;
    manageTelegram: boolean;
    manageSecurity: boolean;
    manageAppearance: boolean;
    manageIdCards: boolean;
    clearCache: boolean;
    testChannels: boolean;
    /** Whether the API Management module is reachable for this user. */
    apiManagement?: boolean;
};

type RoleOption = {
    id: string;
    name: string;
    guard_name: string;
    users_count: number;
};

type Props = {
    settingGroups: Record<string, SettingsGroupPayload>;
    roles: RoleOption[];
    can: SettingsCan;
};

const mfaFieldKeys = ['mfa_enabled', 'mfa_required_for_all', 'mfa_required_role_ids'];
const defaultPasswordFieldKeys = [
    'default_password_enabled',
    'default_password_hash',
    'force_change_default_password',
];

type FormValue = string | number | boolean | string[] | File | null;
type FormShape = Record<string, FormValue>;

const tabs: { id: string; labelKey: string; routeName: string; canKey: keyof SettingsCan }[] = [
    { id: 'general', labelKey: 'settings.tabs.general', routeName: 'system-settings.general.update', canKey: 'manageGeneral' },
    { id: 'localization', labelKey: 'settings.tabs.localization', routeName: 'system-settings.localization.update', canKey: 'manageLocalization' },
    { id: 'notifications', labelKey: 'settings.tabs.notifications', routeName: 'system-settings.notifications.update', canKey: 'manageNotifications' },
    { id: 'email', labelKey: 'settings.tabs.email', routeName: 'system-settings.email.update', canKey: 'manageEmail' },
    { id: 'sms', labelKey: 'settings.tabs.sms', routeName: 'system-settings.sms.update', canKey: 'manageSms' },
    { id: 'telegram', labelKey: 'settings.tabs.telegram', routeName: 'system-settings.telegram.update', canKey: 'manageTelegram' },
    { id: 'security', labelKey: 'settings.tabs.security', routeName: 'system-settings.security.update', canKey: 'manageSecurity' },
    { id: 'appearance', labelKey: 'settings.tabs.appearance', routeName: 'system-settings.appearance.update', canKey: 'manageAppearance' },
    { id: 'id_cards', labelKey: 'settings.tabs.id_cards', routeName: 'system-settings.id-cards.update', canKey: 'manageIdCards' },
];

function toInitialValue(field: SettingsField): FormValue {
    if (field.type === 'file' || field.type === 'image') {
        return null;
    }

    if (field.is_encrypted) {
        return '';
    }

    if (field.type === 'boolean') {
        return field.value !== null && field.value !== undefined ? Boolean(field.value) : Boolean(field.default);
    }

    if (field.type === 'integer') {
        const v = field.value ?? field.default;
        return typeof v === 'number' ? v : (v ? Number(v) : null);
    }

    if (field.type === 'json' || field.type === 'multiselect' || field.key === 'supported_locales' || field.key === 'allowed_file_types' || field.key === 'allowed_upload_mime_types') {
        const v = field.value ?? field.default;
        return Array.isArray(v) ? v.filter((item): item is string => typeof item === 'string') : [];
    }

    // For select fields: always have a valid value — fall back to default then first option
    if (field.type === 'select') {
        const v = field.value ?? field.default;
        if (v !== null && v !== undefined && v !== '') {
            return String(v);
        }
        if (Array.isArray(field.options) && field.options.length > 0) {
            return field.options[0];
        }
        return '';
    }

    const v = field.value ?? null;
    if (v === null || v === undefined) {
        return '';
    }

    return String(v);
}

function buildInitialData(fields: SettingsField[]): FormShape {
    return fields.reduce<FormShape>((carry, field) => {
        carry[field.key] = toInitialValue(field);
        return carry;
    }, {});
}

function normalizeForSubmit(field: SettingsField, value: FormValue): FormValue {
    if (field.type === 'file' || field.type === 'image') {
        return value instanceof File ? value : null;
    }

    if (field.type === 'boolean') {
        return Boolean(value);
    }

    if (field.type === 'integer') {
        return value === '' ? null : value;
    }

    if (field.type === 'json' || field.type === 'multiselect' || field.key === 'supported_locales' || field.key === 'allowed_file_types' || field.key === 'allowed_upload_mime_types') {
        return Array.isArray(value) ? value : [];
    }

    return value === '' ? null : value;
}

// ── Appearance preview panel ───────────────────────────────────────────────────

function AppearancePreview({ data }: { data: FormShape }) {
    const { t } = useLocale();

    return (
        <div className="flex flex-col gap-5 sticky top-4">
            <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <p className="mb-4 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                    {t('settings.groups.appearancePreview')}
                </p>

                {/* Color swatches */}
                <div className="flex flex-wrap gap-2 mb-4">
                    {(['primary_color', 'secondary_color', 'accent_color'] as const).map((key) => (
                        <div key={key} className="flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2 dark:border-slate-700">
                            <span
                                className="h-5 w-5 rounded-full border border-black/10"
                                style={{ backgroundColor: String(data[key] ?? '#2563EB') }}
                            />
                            <span className="text-[11px] text-gray-500 dark:text-slate-400">{key.replace('_color', '')}</span>
                        </div>
                    ))}
                </div>

                {/* Sample card */}
                <div className="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div className="flex items-start justify-between gap-2">
                        <div>
                            <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('settings.fields.sampleCardTitle')}</p>
                            <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('settings.fields.sampleCardText')}</p>
                        </div>
                        <span
                            className="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold text-white"
                            style={{ backgroundColor: String(data.accent_color ?? '#F97316') }}
                        >
                            {t('settings.fields.sampleBadge')}
                        </span>
                    </div>
                    <div className="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="rounded-xl px-4 py-2 text-sm font-medium text-white"
                            style={{ backgroundColor: String(data.primary_color ?? '#2563EB') }}
                        >
                            {t('common.save')}
                        </button>
                        <button
                            type="button"
                            className="rounded-xl border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-slate-700 dark:text-slate-200"
                        >
                            {t('common.cancel')}
                        </button>
                    </div>
                </div>

                {/* Theme/density preview */}
                <div className="mt-4 grid grid-cols-2 gap-2 text-xs">
                    <div className="rounded-xl border border-gray-100 bg-gray-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950">
                        <p className="text-gray-400 dark:text-slate-500">Theme</p>
                        <p className="font-medium text-gray-800 dark:text-slate-200 capitalize">{String(data.default_theme ?? 'system')}</p>
                    </div>
                    <div className="rounded-xl border border-gray-100 bg-gray-50 px-3 py-2 dark:border-slate-800 dark:bg-slate-950">
                        <p className="text-gray-400 dark:text-slate-500">Density</p>
                        <p className="font-medium text-gray-800 dark:text-slate-200 capitalize">{String(data.table_density ?? 'comfortable')}</p>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ── Branding preview panel ─────────────────────────────────────────────────────

function BrandingPreview({ data }: { data: FormShape }) {
    const { t } = useLocale();

    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900 sticky top-4">
            <p className="mb-4 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                {t('settings.groups.brandingPreview')}
            </p>
            <div className="space-y-3">
                <div className="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-950">
                    <p className="text-[10px] uppercase tracking-wider text-gray-400 dark:text-slate-500">{t('settings.tabs.general')}</p>
                    <p className="mt-1 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {(data.application_name as string) || 'Application Name'}
                    </p>
                </div>
                <div className="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-950">
                    <p className="text-[10px] uppercase tracking-wider text-gray-400 dark:text-slate-500">Short name</p>
                    <p className="mt-1 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {(data.application_short_name as string) || 'Short Name'}
                    </p>
                </div>
                <div className="rounded-xl border border-gray-100 bg-gray-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-950">
                    <p className="text-[10px] uppercase tracking-wider text-gray-400 dark:text-slate-500">{t('settings.organizationName')}</p>
                    <p className="mt-1 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {(data.organization_name as string) || 'Organization'}
                    </p>
                </div>
            </div>
        </div>
    );
}

// ── ID Card preview panel ──────────────────────────────────────────────────────

function IdCardPreview({ data }: { data: FormShape }) {
    const { t, locale } = useLocale();

    const frontFrom = String(data.front_bg_from ?? '#1D4ED8');
    const frontTo   = String(data.front_bg_to   ?? '#1E3A8A');
    const textPri   = String(data.front_text_primary   ?? '#FFFFFF');
    const textSec   = String(data.front_text_secondary ?? '#BFDBFE');
    const backFrom  = String(data.back_bg_from  ?? '#1E293B');
    const backTo    = String(data.back_bg_to    ?? '#0F172A');
    const backText  = String(data.back_text_color ?? '#94A3B8');
    const cityName  = locale === 'am'
        ? String(data.city_name_am ?? data.city_name_en ?? 'Addis Ababa City Administration')
        : String(data.city_name_en ?? 'Addis Ababa City Administration');
    const bureauName = locale === 'am'
        ? String(data.bureau_name_am ?? data.bureau_name_en ?? 'Public Service & HRD Bureau')
        : String(data.bureau_name_en ?? 'Public Service & HRD Bureau');
    const returnAddress = locale === 'am'
        ? String(data.return_address_am ?? data.return_address_en ?? '')
        : String(data.return_address_en ?? '');
    const showMagStripe = data.show_magnetic_stripe !== false;
    const template = resolveIdCardTemplate(data.template);
    const padding = String(data.card_padding ?? 'normal');
    const padCls  = padding === 'compact' ? 'px-3 pb-2' : padding === 'spacious' ? 'px-5 pb-5' : 'px-4 pb-3';
    const frontBackground = template === 'modern'
        ? `linear-gradient(110deg, ${frontFrom} 0%, ${frontFrom} 62%, ${frontTo} 62%, ${frontTo} 100%)`
        : template === 'minimal'
            ? `linear-gradient(180deg, ${frontFrom} 0%, ${frontFrom} 88%, ${frontTo} 88%, ${frontTo} 100%)`
            : `linear-gradient(to bottom right, ${frontFrom}, ${frontTo})`;
    const backBackground = template === 'modern'
        ? `linear-gradient(110deg, ${backFrom} 0%, ${backFrom} 62%, ${backTo} 62%, ${backTo} 100%)`
        : template === 'minimal'
            ? `linear-gradient(180deg, ${backFrom} 0%, ${backFrom} 94%, ${backTo} 94%, ${backTo} 100%)`
            : `linear-gradient(to bottom right, ${backFrom}, ${backTo})`;
    const cardRadius = template === 'modern' ? '1.25rem' : template === 'minimal' ? '0.5rem' : '0.75rem';

    return (
        <div className="flex flex-col gap-5 sticky top-4">
            <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <p className="mb-4 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-slate-500">
                    {t('settings.groups.idCardPreview')}
                </p>
                <p className="mb-3 text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {t(`settings.idCardTemplates.${template}.name`)}
                </p>

                {/* Front card */}
                <div
                    className="relative overflow-hidden rounded-xl shadow-lg mb-3"
                    style={{
                        aspectRatio: '85.6/54',
                        borderRadius: cardRadius,
                        background: frontBackground,
                    }}
                >
                    {template !== 'minimal' && (
                        <div
                            className="absolute inset-0 pointer-events-none"
                            style={{
                                backgroundImage: template === 'modern'
                                    ? 'repeating-linear-gradient(135deg, rgba(255,255,255,0.04) 0 1px, transparent 1px 12px)'
                                    : 'radial-gradient(circle, rgba(255,255,255,0.06) 1px, transparent 1px)',
                                backgroundSize: template === 'classic' ? '12px 12px' : undefined,
                            }}
                        />
                    )}
                    <div className={`absolute inset-x-0 top-0 flex items-center gap-2 px-3 py-1.5 ${template === 'modern' ? 'border-b border-white/20 bg-black/10' : 'bg-white/10'}`}>
                        <div className="h-6 w-6 rounded-full bg-white/20 flex items-center justify-center text-[9px] font-bold" style={{ color: textPri }}>
                            AA
                        </div>
                        <div className="min-w-0">
                            <p className="truncate text-[9px] font-semibold leading-tight" style={{ color: textPri }}>{cityName}</p>
                            <p className="truncate text-[8px] leading-tight" style={{ color: textSec }}>{bureauName}</p>
                        </div>
                    </div>
                    <div className={`absolute inset-x-0 top-10 bottom-0 flex gap-2 ${padCls}`}>
                        <div className={`${template === 'modern' ? 'h-12 w-12 rounded-full border-2' : 'h-14 w-11 rounded-md border'} bg-white/20 border-white/30 flex items-center justify-center`}>
                            <span className="text-[7px]" style={{ color: textSec }}>Photo</span>
                        </div>
                        <div className="flex-1 min-w-0 flex flex-col justify-between">
                            <div>
                                <p className="text-[10px] font-bold leading-tight" style={{ color: textPri }}>Sample Employee</p>
                                <p className="text-[8px] leading-tight mt-0.5" style={{ color: textSec }}>Position Title</p>
                            </div>
                            <div className="space-y-0.5">
                                <p className="text-[8px] font-mono" style={{ color: textPri }}>ID: EMP-00001</p>
                                <p className="text-[7px]" style={{ color: textSec }}>Exp: 2027-01</p>
                            </div>
                        </div>
                    </div>
                    {template === 'classic' && <div className="absolute -right-4 top-0 bottom-0 w-12 bg-white/5 -skew-x-12 pointer-events-none" />}
                    {template === 'modern' && <div className="absolute -right-5 -top-5 h-20 w-20 rounded-full border-[10px] border-white/10 pointer-events-none" />}
                </div>

                {/* Back card */}
                <div
                    className="relative overflow-hidden rounded-xl shadow-lg"
                    style={{
                        aspectRatio: '85.6/54',
                        borderRadius: cardRadius,
                        background: backBackground,
                    }}
                >
                    {showMagStripe && (
                        <div className={[
                            'absolute bg-black/40 pointer-events-none',
                            template === 'modern' ? 'left-[34%] right-0 top-4 h-4 rounded-l-full' : '',
                            template === 'minimal' ? 'inset-x-0 top-3 h-2' : '',
                            template === 'classic' ? 'inset-x-0 top-3 h-5' : '',
                        ].join(' ')} />
                    )}
                    <div className={`absolute inset-0 flex items-center justify-center gap-3 ${padCls} pt-9`}>
                        <div className="h-20 w-20 rounded bg-white p-1 flex items-center justify-center">
                            <div className="h-full w-full rounded-sm" style={{ background: backFrom, opacity: 0.6 }} />
                        </div>
                        <div className="flex-1 min-w-0 flex flex-col justify-between self-stretch pt-1">
                            <p className="text-[7px] uppercase tracking-wide" style={{ color: backText }}>Official Card</p>
                            <div>
                                <p className="font-mono text-[7px]" style={{ color: backText }}>CARD-000001</p>
                                <p className="text-[6px] leading-tight truncate mt-0.5" style={{ color: backText, opacity: 0.7 }}>{returnAddress}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ── Group form panel ───────────────────────────────────────────────────────────

function GroupFormPanel({
    groupId,
    payload,
    routeName,
    readOnly,
    canTest,
    roles,
}: {
    groupId: string;
    payload: SettingsGroupPayload;
    routeName: string;
    readOnly: boolean;
    canTest: boolean;
    roles: RoleOption[];
}) {
    const { locale, t } = useLocale();
    const initial = useMemo(() => buildInitialData(payload.fields), [payload.fields]);
    const form = useForm<FormShape>(initial);

    const isSecurity = groupId === 'security';
    const genericFields = isSecurity
        ? payload.fields.filter((field) => !mfaFieldKeys.includes(field.key) && !defaultPasswordFieldKeys.includes(field.key))
        : payload.fields;
    const defaultPasswordFields = isSecurity
        ? payload.fields.filter((field) => defaultPasswordFieldKeys.includes(field.key))
        : [];
    const defaultPasswordHashField = defaultPasswordFields.find((field) => field.key === 'default_password_hash');
    const mfaToggleFields = isSecurity
        ? payload.fields.filter((field) => field.key === 'mfa_enabled' || field.key === 'mfa_required_for_all')
        : [];
    const hasMfaRoleField = isSecurity && payload.fields.some((field) => field.key === 'mfa_required_role_ids');

    const mfaEnabled = Boolean(form.data.mfa_enabled);
    const mfaRequiredForAll = Boolean(form.data.mfa_required_for_all);
    const selectedMfaRoleIds = Array.isArray(form.data.mfa_required_role_ids)
        ? (form.data.mfa_required_role_ids as string[])
        : [];
    const mfaRolesDisabled = readOnly || !mfaEnabled || mfaRequiredForAll;

    const toggleMfaRole = (roleId: string) => {
        const next = selectedMfaRoleIds.includes(roleId)
            ? selectedMfaRoleIds.filter((id) => id !== roleId)
            : [...selectedMfaRoleIds, roleId];
        form.setData('mfa_required_role_ids', next);
    };

    const isDirty = useMemo(
        () => JSON.stringify(form.data) !== JSON.stringify(initial),
        [form.data, initial],
    );

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.transform((data) => {
            const values = payload.fields.reduce<Record<string, FormValue>>((carry, field) => {
                carry[field.key] = normalizeForSubmit(field, data[field.key]);
                return carry;
            }, {});

            if (isSecurity) {
                values.default_password_hash_confirmation = data.default_password_hash_confirmation ?? '';
            }

            return { _method: 'patch', ...values };
        });

        form.post(route(routeName), {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onSuccess: () => {
                if (isSecurity) {
                    form.setData((data) => ({
                        ...data,
                        default_password_hash: '',
                        default_password_hash_confirmation: '',
                    }));
                }
            },
        });
    };

    const triggerChannelTest = () => {
        const routeMap: Record<string, string> = {
            email: 'system-settings.test-email',
            sms: 'system-settings.test-sms',
            telegram: 'system-settings.test-telegram',
        };

        const endpoint = routeMap[groupId];
        if (endpoint) {
            router.post(route(endpoint), {}, { preserveScroll: true });
        }
    };

    const saveBar = (
        <div className="sticky bottom-0 z-10 -mx-5 -mb-5 mt-6 border-t border-gray-200 bg-white/95 px-5 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
            <div className="flex items-center justify-between gap-3">
                <span className="text-xs text-gray-500 dark:text-slate-400">
                    {isDirty && !readOnly ? t('settings.unsavedChanges') : ''}
                </span>
                <Button
                    type="submit"
                    size="sm"
                    loading={form.processing}
                    disabled={readOnly || !isDirty}
                >
                    {t('common.save')}
                </Button>
            </div>
        </div>
    );

    const mainCard = (
        <SettingsCard
            title={t(`settings.tabs.${groupId}`)}
            description={t(`settings.descriptions.${groupId}`)}
            actions={canTest ? (
                <TestChannelButton
                    onClick={triggerChannelTest}
                    disabled={readOnly}
                    processing={form.processing}
                />
            ) : undefined}
        >
            {genericFields.map((field) => (
                <SettingField
                    key={field.key}
                    field={field}
                    locale={locale}
                    value={form.data[field.key]}
                    error={form.errors[field.key]}
                    disabled={readOnly}
                    onChange={(nextValue) => form.setData(field.key, nextValue)}
                />
            ))}
        </SettingsCard>
    );

    return (
        <form onSubmit={submit}>
            <SettingsSection
                title={t(`settings.tabs.${groupId}`)}
                description={t(`settings.descriptions.${groupId}`)}
                actions={readOnly ? (
                    <span className="rounded-full bg-gray-200 px-3 py-1 text-xs font-medium text-gray-600 dark:bg-slate-800 dark:text-slate-300">
                        {t('settings.readOnly')}
                    </span>
                ) : undefined}
            >
                {groupId === 'appearance' ? (
                    <div className="grid gap-6 lg:grid-cols-[1fr_280px]">
                        <div>{mainCard}</div>
                        <AppearancePreview data={form.data} />
                    </div>
                ) : groupId === 'general' ? (
                    <div className="grid gap-6 lg:grid-cols-[1fr_240px]">
                        <div>{mainCard}</div>
                        <BrandingPreview data={form.data} />
                    </div>
                ) : groupId === 'id_cards' ? (
                    <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
                        <div>{mainCard}</div>
                        <IdCardPreview data={form.data} />
                    </div>
                ) : (
                    mainCard
                )}

                {isSecurity && mfaToggleFields.length > 0 && (
                    <SettingsCard
                        title={t('settings.mfa.title')}
                        description={t('settings.mfa.helper')}
                    >
                        {mfaToggleFields
                            .filter((field) => field.key === 'mfa_enabled' || mfaEnabled)
                            .map((field) => (
                                <SettingField
                                    key={field.key}
                                    field={field}
                                    locale={locale}
                                    value={form.data[field.key]}
                                    error={form.errors[field.key]}
                                    disabled={readOnly}
                                    onChange={(nextValue) => form.setData(field.key, nextValue)}
                                />
                            ))}

                        {hasMfaRoleField && mfaEnabled && (
                            <div className="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-3 md:items-start">
                                <div>
                                    <span className="text-sm font-medium text-gray-900 dark:text-slate-100">
                                        {t('settings.mfa.requireRoles')}
                                    </span>
                                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                        {t('settings.mfa.selectRoles')}
                                    </p>
                                </div>
                                <div className="space-y-2 md:col-span-2">
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {roles.map((role) => (
                                            <label
                                                key={role.id}
                                                className={[
                                                    'flex items-center gap-2 rounded-xl border border-gray-200 px-3 py-2 dark:border-slate-700',
                                                    mfaRolesDisabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
                                                ].join(' ')}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={selectedMfaRoleIds.includes(role.id)}
                                                    disabled={mfaRolesDisabled}
                                                    onChange={() => toggleMfaRole(role.id)}
                                                    className="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-slate-600"
                                                />
                                                <span className="min-w-0 flex-1 truncate text-sm text-gray-800 dark:text-slate-200">
                                                    {role.name}
                                                </span>
                                                <span className="shrink-0 text-[11px] text-gray-400 dark:text-slate-500">
                                                    {role.guard_name} · {role.users_count}
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                    {mfaRequiredForAll && (
                                        <p className="text-xs text-gray-500 dark:text-slate-400">
                                            {t('settings.mfa.requireAllActive')}
                                        </p>
                                    )}
                                    {form.errors.mfa_required_role_ids && (
                                        <p className="text-sm text-red-600 dark:text-red-400">
                                            {form.errors.mfa_required_role_ids}
                                        </p>
                                    )}
                                </div>
                            </div>
                        )}
                    </SettingsCard>
                )}

                {isSecurity && defaultPasswordFields.length > 0 && (
                    <SettingsCard
                        title={t('settings.defaultPassword.title')}
                        description={t('settings.defaultPassword.helper')}
                    >
                        <div className="px-5 pt-4">
                            <span
                                className={[
                                    'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                                    defaultPasswordHashField?.configured
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                        : 'bg-gray-200 text-gray-600 dark:bg-slate-800 dark:text-slate-300',
                                ].join(' ')}
                            >
                                {defaultPasswordHashField?.configured
                                    ? t('settings.defaultPassword.configured')
                                    : t('settings.defaultPassword.notConfigured')}
                            </span>
                        </div>

                        {defaultPasswordFields.map((field) => (
                            <SettingField
                                key={field.key}
                                field={field}
                                locale={locale}
                                value={form.data[field.key]}
                                error={form.errors[field.key]}
                                disabled={readOnly}
                                onChange={(nextValue) => form.setData(field.key, nextValue)}
                            />
                        ))}

                        <div className="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-3 md:items-start">
                            <label
                                htmlFor="default_password_hash_confirmation"
                                className="text-sm font-medium text-gray-900 dark:text-slate-100"
                            >
                                {t('settings.defaultPassword.confirm')}
                            </label>
                            <div className="space-y-2 md:col-span-2">
                                <input
                                    id="default_password_hash_confirmation"
                                    type="password"
                                    value={String(form.data.default_password_hash_confirmation ?? '')}
                                    disabled={readOnly}
                                    autoComplete="new-password"
                                    onChange={(event) => form.setData('default_password_hash_confirmation', event.target.value)}
                                    className="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                                />
                                {form.errors.default_password_hash_confirmation && (
                                    <p className="text-sm text-red-600 dark:text-red-400">
                                        {form.errors.default_password_hash_confirmation}
                                    </p>
                                )}
                            </div>
                        </div>
                    </SettingsCard>
                )}

                {groupId === 'security' && (
                    <SettingsCard
                        title={t('settings.groups.securityWarning')}
                        description={t('settings.fields.securityNotice')}
                    >
                        <div className="px-5 py-4 text-sm text-amber-800 dark:text-amber-200">
                            {t('settings.fields.securityNotice')}
                        </div>
                    </SettingsCard>
                )}
            </SettingsSection>

            {saveBar}
        </form>
    );
}

export default function SystemSettingsIndex({ settingGroups, roles, can }: Props) {
    const { t } = useLocale();
    const [activeTab, setActiveTab] = useState<string>('general');

    // Field-group tabs — these drive the editable panels below.
    const availableTabs = tabs.filter((tab) => settingGroups[tab.id] !== undefined);

    // Display list adds API Management as a link tab beside Security. It is a
    // separate CRUD module rather than a settings field group, so it navigates
    // instead of switching the panel, and only appears when permitted.
    const displayTabs: { id: string; labelKey: string; href?: string }[] = (() => {
        const list: { id: string; labelKey: string; href?: string }[] = availableTabs.map((tab) => ({
            id: tab.id,
            labelKey: tab.labelKey,
        }));

        if (!can.apiManagement) {
            return list;
        }

        const securityIndex = list.findIndex((tab) => tab.id === 'security');

        list.splice(securityIndex >= 0 ? securityIndex + 1 : list.length, 0, {
            id: 'api_management',
            labelKey: 'settings.tabs.api_management',
            href: route('api-management.index'),
        });

        return list;
    })();

    const clearCache = () => {
        router.post(route('system-settings.clear-cache'), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('settings.title')}
                    description={t('settings.subtitle')}
                    actions={can.clearCache ? (
                        <Button type="button" variant="outline" size="sm" onClick={clearCache}>
                            {t('settings.clearCache')}
                        </Button>
                    ) : undefined}
                />
            )}
        >
            <Head title={t('settings.title')} />

            <div className="space-y-0 rounded-2xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900 overflow-hidden">
                <SettingsTabs tabs={displayTabs} activeTab={activeTab} onSelect={setActiveTab} />

                <div className="p-5 bg-gray-50 dark:bg-slate-950 min-h-[400px]">
                    {availableTabs.map((tab) => {
                        if (tab.id !== activeTab) {
                            return null;
                        }

                        const payload = settingGroups[tab.id];
                        if (!payload) {
                            return null;
                        }

                        return (
                            <GroupFormPanel
                                key={tab.id}
                                groupId={tab.id}
                                payload={payload}
                                routeName={tab.routeName}
                                readOnly={!can[tab.canKey]}
                                canTest={can.testChannels && ['email', 'sms', 'telegram'].includes(tab.id)}
                                roles={roles ?? []}
                            />
                        );
                    })}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
