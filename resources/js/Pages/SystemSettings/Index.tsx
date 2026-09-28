import PageHeader from '@/Components/PageHeader';
import { BellIcon, CheckCircle, CreditCard, GlobeIcon, KeyIcon, MailIcon, MessageSquareIcon, PaletteIcon, RefreshIcon, SendIcon, SettingsIcon, ShieldCheck } from '@/Components/Icons';
import SettingField from '@/Components/settings/SettingField';
import SettingsCard from '@/Components/settings/SettingsCard';
import SettingsNav, { type SettingsNavGroup } from '@/Components/settings/SettingsNav';
import { AppearancePreview, BrandingPreview, IdCardTemplatesPanel } from '@/Components/settings/SettingsPreviews';
import TestChannelButton from '@/Components/settings/TestChannelButton';
import { cardsFor, type FieldCard } from '@/Components/settings/sections';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { useConfirm } from '@/hooks/useConfirm';
import { useLocale } from '@/hooks/useLocale';
import type { SettingsField, SettingsGroupPayload } from '@/lib/settings';
import { Alert, Button, EmptyState, FieldError, SearchInput, StatusBadge, cx } from '@euisis/ui';
import { Head, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type ComponentType, type FormEvent, type SVGProps } from 'react';

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
    viewIdCardTemplates?: boolean;
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

type FormValue = string | number | boolean | string[] | File | null;
type FormShape = Record<string, FormValue>;
type NavGroupKey = 'organization' | 'communication' | 'security';

type Section = {
    id: string;
    routeName: string;
    canKey: keyof SettingsCan;
    icon: ComponentType<SVGProps<SVGSVGElement>>;
    group: NavGroupKey;
};

const SECTIONS: Section[] = [
    { id: 'general', routeName: 'system-settings.general.update', canKey: 'manageGeneral', icon: SettingsIcon, group: 'organization' },
    { id: 'localization', routeName: 'system-settings.localization.update', canKey: 'manageLocalization', icon: GlobeIcon, group: 'organization' },
    { id: 'appearance', routeName: 'system-settings.appearance.update', canKey: 'manageAppearance', icon: PaletteIcon, group: 'organization' },
    { id: 'id_cards', routeName: 'system-settings.id-cards.update', canKey: 'manageIdCards', icon: CreditCard, group: 'organization' },
    { id: 'notifications', routeName: 'system-settings.notifications.update', canKey: 'manageNotifications', icon: BellIcon, group: 'communication' },
    { id: 'email', routeName: 'system-settings.email.update', canKey: 'manageEmail', icon: MailIcon, group: 'communication' },
    { id: 'sms', routeName: 'system-settings.sms.update', canKey: 'manageSms', icon: MessageSquareIcon, group: 'communication' },
    { id: 'telegram', routeName: 'system-settings.telegram.update', canKey: 'manageTelegram', icon: SendIcon, group: 'communication' },
    { id: 'security', routeName: 'system-settings.security.update', canKey: 'manageSecurity', icon: ShieldCheck, group: 'security' },
];

const NAV_GROUPS: NavGroupKey[] = ['organization', 'communication', 'security'];

const CHANNEL_TESTS: Record<string, string> = {
    email: 'system-settings.test-email',
    sms: 'system-settings.test-sms',
    telegram: 'system-settings.test-telegram',
};

/** The notifications switch that turns each channel on, for the "Off" marker in the section list. */
const CHANNEL_SWITCHES: Record<string, string> = {
    email: 'email_notifications_enabled',
    sms: 'sms_notifications_enabled',
    telegram: 'telegram_notifications_enabled',
};

const LIST_KEYS = ['supported_locales', 'allowed_file_types', 'allowed_upload_mime_types'];

function isList(field: SettingsField): boolean {
    return field.type === 'json' || field.type === 'multiselect' || LIST_KEYS.includes(field.key);
}

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

    if (isList(field)) {
        const v = field.value ?? field.default;
        return Array.isArray(v) ? v.filter((item): item is string => typeof item === 'string') : [];
    }

    // A select always holds a valid option: its value, then its default, then its first option.
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

    if (isList(field)) {
        return Array.isArray(value) ? value : [];
    }

    return value === '' ? null : value;
}

function initialSection(available: string[]): string {
    const requested = new URLSearchParams(window.location.search).get('tab');
    return requested && available.includes(requested) ? requested : available[0] ?? '';
}

export default function SystemSettingsIndex({ settingGroups, roles, can }: Props) {
    const { t } = useLocale();
    const { confirm } = useConfirm();
    const sections = SECTIONS.filter((section) => settingGroups[section.id] !== undefined);
    const [active, setActive] = useState(() => initialSection(sections.map((section) => section.id)));
    const [dirty, setDirty] = useState(false);
    const [clearing, setClearing] = useState(false);
    const contentRef = useRef<HTMLDivElement>(null);

    const channelStatus = (id: string): string | undefined => {
        const field = settingGroups.notifications?.fields.find((entry) => entry.key === CHANNEL_SWITCHES[id]);
        return field && (field.value ?? field.default) === false ? t('settings.channelOff') : undefined;
    };

    const navGroups: SettingsNavGroup[] = NAV_GROUPS.map((group) => ({
        label: t(`settings.navGroups.${group}`),
        items: [
            ...sections.filter((section) => section.group === group).map((section) => ({
                id: section.id,
                label: t(`settings.tabs.${section.id}`),
                icon: section.icon,
                status: channelStatus(section.id),
            })),
            // A separate module rather than a group of setting fields, so it links out.
            ...(group === 'security' && can.apiManagement
                ? [{ id: 'api_management', label: t('settings.tabs.api_management'), icon: KeyIcon, href: route('api-management.index') }]
                : []),
        ],
    })).filter((group) => group.items.length > 0);

    const select = async (id: string) => {
        if (id === active) return;
        if (dirty) {
            const { confirmed } = await confirm({
                title: t('settings.discard.title'),
                description: t('settings.discard.description'),
                confirmLabel: t('settings.discard.confirm'),
                cancelLabel: t('settings.discard.cancel'),
                variant: 'warning',
            });
            if (!confirmed) return;
        }
        setDirty(false);
        setActive(id);
        // Keep the section in the URL so a refresh or a shared link opens it; Inertia's history state is kept as is.
        const url = new URL(window.location.href);
        url.searchParams.set('tab', id);
        window.history.replaceState(window.history.state, '', url);
        if (contentRef.current && contentRef.current.getBoundingClientRect().top < 0) {
            contentRef.current.scrollIntoView({ block: 'start' });
        }
    };

    // Leaving the page with unsaved edits asks first. The page's own saves, tests and cache clears are not visits away.
    useEffect(() => {
        if (!dirty) return;
        const beforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', beforeUnload);
        const removeGuard = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (visit.method !== 'get' || visit.prefetch || visit.only.length > 0) return;
            if (!window.confirm(t('settings.discard.leave'))) event.preventDefault();
        });
        return () => {
            window.removeEventListener('beforeunload', beforeUnload);
            removeGuard();
        };
    }, [dirty, t]);

    const clearCache = () => {
        router.post(route('system-settings.clear-cache'), {}, {
            preserveScroll: true,
            onStart: () => setClearing(true),
            onFinish: () => setClearing(false),
        });
    };

    const current = sections.find((section) => section.id === active);
    const sidebarColor = settingGroups.appearance?.fields.find((field) => field.key === 'sidebar_color')?.value;

    return (
        <AuthenticatedLayout
            header={(
                <PageHeader
                    title={t('settings.title')}
                    description={t('settings.subtitle')}
                    actions={can.clearCache ? (
                        <Button variant="outline" onClick={clearCache} loading={clearing} icon={<RefreshIcon className="h-4 w-4" />} title={t('settings.clearCacheHelp')}>
                            {t('settings.clearCache')}
                        </Button>
                    ) : undefined}
                />
            )}
        >
            <Head title={t('settings.title')} />

            {!current ? (
                <EmptyState icon={<SettingsIcon className="h-8 w-8" />} title={t('settings.noPermission')} />
            ) : (
                <div className="grid items-start gap-5 lg:grid-cols-[13rem_minmax(0,1fr)] lg:gap-8 xl:grid-cols-[14.5rem_minmax(0,1fr)]">
                    <SettingsNav groups={navGroups} active={active} onSelect={(id) => void select(id)} />
                    <div ref={contentRef} className="min-w-0 max-w-5xl scroll-mt-20">
                        <GroupFormPanel
                            key={current.id}
                            section={current.id}
                            payload={settingGroups[current.id]}
                            routeName={current.routeName}
                            readOnly={!can[current.canKey]}
                            canTest={can.testChannels && current.id in CHANNEL_TESTS}
                            roles={roles ?? []}
                            canViewTemplates={can.viewIdCardTemplates === true}
                            sidebarColor={typeof sidebarColor === 'string' ? sidebarColor : undefined}
                            onDirtyChange={setDirty}
                        />
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

// ── One section's form ───────────────────────────────────────────────────────

function GroupFormPanel({
    section,
    payload,
    routeName,
    readOnly,
    canTest,
    roles,
    canViewTemplates,
    sidebarColor,
    onDirtyChange,
}: {
    section: string;
    payload: SettingsGroupPayload;
    routeName: string;
    readOnly: boolean;
    canTest: boolean;
    roles: RoleOption[];
    canViewTemplates: boolean;
    sidebarColor?: string;
    onDirtyChange: (dirty: boolean) => void;
}) {
    const { locale, t } = useLocale();
    const initial = useMemo(() => buildInitialData(payload.fields), [payload.fields]);
    const form = useForm<FormShape>(initial);
    // Remounts the fields after a save or discard, so file pickers and list inputs drop what they held.
    const [resetKey, setResetKey] = useState(0);
    const [testing, setTesting] = useState(false);
    const formRef = useRef<HTMLFormElement>(null);

    const isDirty = useMemo(() => JSON.stringify(form.data) !== JSON.stringify(initial), [form.data, initial]);
    const editable = !readOnly;

    useEffect(() => { onDirtyChange(isDirty && editable); }, [editable, isDirty, onDirtyChange]);

    const reset = (next: FormShape) => {
        form.setData(next);
        form.clearErrors();
        setResetKey((key) => key + 1);
    };

    const submit = (event?: FormEvent<HTMLFormElement>) => {
        event?.preventDefault();
        if (!editable || !isDirty || form.processing) return;

        form.transform((data) => ({
            _method: 'patch',
            ...payload.fields.reduce<Record<string, FormValue>>((carry, field) => {
                carry[field.key] = normalizeForSubmit(field, data[field.key]);
                return carry;
            }, {}),
        }));

        form.post(route(routeName), {
            preserveScroll: true,
            preserveState: true,
            forceFormData: true,
            onSuccess: (page) => {
                // Start again from what was saved: secrets and uploads clear, everything else matches.
                const saved = (page.props.settingGroups as Record<string, SettingsGroupPayload> | undefined)?.[section]?.fields;
                if (saved) reset(buildInitialData(saved));
            },
            onError: () => {
                window.requestAnimationFrame(() => formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus());
            },
        });
    };

    // Ctrl/Cmd+S saves the open section.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                formRef.current?.requestSubmit();
            }
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, []);

    const test = () => {
        router.post(route(CHANNEL_TESTS[section]), {}, {
            preserveScroll: true,
            onStart: () => setTesting(true),
            onFinish: () => setTesting(false),
        });
    };

    const optional = (key: string) => {
        const text = t(key);
        return text === key ? undefined : text;
    };
    const cardTitle = (card: FieldCard) => (card.key === 'other'
        ? t('settings.sections.other')
        : t(section === 'id_cards' ? `settings.idCardCleanup.${card.key}` : `settings.sections.${section}.${card.key}`));
    const cardHelp = (card: FieldCard) => (card.key === 'other'
        ? undefined
        : optional(section === 'id_cards' ? `settings.idCardCleanup.${card.key}Help` : `settings.sections.${section}.${card.key}Help`));

    const field = (entry: SettingsField, compact = false) => (
        <SettingField
            key={`${entry.key}-${resetKey}`}
            field={entry}
            locale={locale}
            compact={compact}
            value={form.data[entry.key]}
            error={form.errors[entry.key]}
            disabled={!editable || form.processing}
            onChange={(value) => form.setData(entry.key, value)}
        />
    );

    const cards = cardsFor(section, payload.fields);

    return (
        <form ref={formRef} onSubmit={submit} className="space-y-5">
            <header className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="text-lg font-semibold text-[color:var(--app-foreground)]">{t(`settings.tabs.${section}`)}</h2>
                    <p className="mt-1 max-w-2xl text-sm text-[color:var(--app-muted-foreground)]">{t(`settings.descriptions.${section}`)}</p>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <div className="flex items-center gap-2">
                        {readOnly && <StatusBadge tone="neutral">{t('settings.readOnly')}</StatusBadge>}
                        {canTest && (
                            <TestChannelButton onClick={test} processing={testing} disabled={readOnly || isDirty || testing}
                                reason={isDirty ? t('settings.saveBeforeTest') : t('settings.testChannelHelp')} />
                        )}
                    </div>
                    {canTest && isDirty && <p className="text-xs text-[color:var(--app-muted-foreground)]">{t('settings.saveBeforeTest')}</p>}
                </div>
            </header>

            {readOnly && <Alert tone="neutral">{t('settings.noPermission')}</Alert>}
            {section === 'security' && <Alert tone="warning" title={t('settings.groups.securityWarning')}>{t('settings.fields.securityNotice')}</Alert>}
            {section === 'general' && <BrandingPreview values={form.data} fields={payload.fields} sidebarColor={sidebarColor} />}
            {section === 'appearance' && <AppearancePreview values={form.data} />}
            {section === 'id_cards' && <IdCardTemplatesPanel canManage={canViewTemplates} />}

            {cards.map((card) => {
                if (section === 'security' && card.key === 'mfa') {
                    return (
                        <MfaCard key={card.key} fields={card.fields} renderField={field} roles={roles} disabled={!editable || form.processing}
                            enabled={Boolean(form.data.mfa_enabled)} requiredForAll={Boolean(form.data.mfa_required_for_all)}
                            selected={Array.isArray(form.data.mfa_required_role_ids) ? form.data.mfa_required_role_ids as string[] : []}
                            error={form.errors.mfa_required_role_ids}
                            onSelectedChange={(ids) => form.setData('mfa_required_role_ids', ids)} />
                    );
                }
                const switchGrid = section === 'id_cards' && card.key === 'visibility';
                return (
                    <SettingsCard key={card.key} title={cardTitle(card)} description={cardHelp(card)}>
                        {switchGrid
                            ? <div className="grid sm:grid-cols-2">{card.fields.map((entry) => field(entry, true))}</div>
                            : card.fields.map((entry) => field(entry))}
                    </SettingsCard>
                );
            })}

            {editable && (
                // Pinned to the bottom of the window only while there is something to save.
                <div className={cx('z-10 -mx-1 px-1 pb-4 pt-1', isDirty && 'sticky bottom-0')}>
                    <div className={cx(
                        'flex flex-wrap items-center justify-between gap-3 rounded-[var(--radius-card)] border px-4 py-3 shadow-lg backdrop-blur-md',
                        isDirty
                            ? 'border-amber-300 bg-amber-50/95 dark:border-amber-500/40 dark:bg-amber-950/90'
                            : 'border-[color:var(--app-border)] bg-[color:color-mix(in_srgb,var(--app-surface)_92%,transparent)]',
                    )}>
                        <p aria-live="polite" className="flex min-w-0 items-center gap-2 text-sm">
                            {isDirty ? (
                                <>
                                    <span aria-hidden="true" className="h-2 w-2 shrink-0 rounded-full bg-amber-500" />
                                    <span className="font-medium text-amber-900 dark:text-amber-100">{t('settings.unsavedChanges')}</span>
                                </>
                            ) : (
                                <>
                                    <CheckCircle aria-hidden="true" className="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                    <span className="text-[color:var(--app-muted-foreground)]">{t('settings.allSaved')}</span>
                                </>
                            )}
                        </p>
                        <div className="flex flex-1 justify-end gap-2 sm:flex-none">
                            {isDirty && (
                                <Button variant="ghost" onClick={() => reset(initial)} disabled={form.processing}>{t('settings.discardChanges')}</Button>
                            )}
                            <Button type="submit" variant="primary" loading={form.processing} disabled={!isDirty} title={t('settings.saveShortcut')}>
                                {t('settings.saveChanges')}
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </form>
    );
}

// ── Multi-factor authentication ─────────────────────────────────────────────

function MfaCard({ fields, renderField, roles, disabled, enabled, requiredForAll, selected, error, onSelectedChange }: {
    fields: SettingsField[];
    renderField: (field: SettingsField) => JSX.Element;
    roles: RoleOption[];
    disabled: boolean;
    enabled: boolean;
    requiredForAll: boolean;
    selected: string[];
    error?: string;
    onSelectedChange: (ids: string[]) => void;
}) {
    const { t } = useLocale();
    const [query, setQuery] = useState('');
    const rolesDisabled = disabled || requiredForAll;
    const toggles = fields.filter((entry) => entry.key === 'mfa_enabled' || (enabled && entry.key === 'mfa_required_for_all'));
    const showRoles = enabled && fields.some((entry) => entry.key === 'mfa_required_role_ids');
    const needle = query.trim().toLocaleLowerCase();
    const shown = needle ? roles.filter((role) => role.name.toLocaleLowerCase().includes(needle)) : roles;

    return (
        <SettingsCard title={t('settings.mfa.title')} description={t('settings.mfa.helper')}>
            {toggles.map((entry) => renderField(entry))}
            {showRoles && (
                // The role list takes the card's full width: role names are long and there can be dozens.
                <div className="space-y-3 px-5 py-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div className="min-w-0">
                            <p id="mfa-roles-label" className="text-sm font-medium text-[color:var(--app-foreground)]">
                                {t('settings.mfa.requireRoles')}
                                {!requiredForAll && selected.length > 0 && (
                                    <span className="ms-2 font-normal tabular-nums text-[color:var(--app-muted-foreground)]">{t('settings.mfa.selectedCount').replace('{{count}}', String(selected.length))}</span>
                                )}
                            </p>
                            <p className="mt-1 text-xs leading-5 text-[color:var(--app-muted-foreground)]">{requiredForAll ? t('settings.mfa.requireAllActive') : t('settings.mfa.selectRoles')}</p>
                        </div>
                        {roles.length > 8 && <SearchInput value={query} onChange={setQuery} label={t('settings.mfa.filterRoles')} placeholder={t('settings.mfa.filterRoles')} className="w-full sm:w-64" />}
                    </div>
                    {roles.length === 0 || shown.length === 0 ? (
                        <p className="text-sm text-[color:var(--app-muted-foreground)]">{roles.length === 0 ? t('settings.mfa.noRoles') : t('settings.mfa.noRolesMatch')}</p>
                    ) : (
                        <div role="group" aria-labelledby="mfa-roles-label" className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {shown.map((role) => {
                                const checked = requiredForAll || selected.includes(role.id);
                                return (
                                    <label key={role.id} className={cx(
                                        'flex min-w-0 items-start gap-2.5 rounded-[var(--radius-control)] border px-3 py-2 text-sm transition-colors',
                                        checked ? 'border-[color:var(--color-primary)] bg-[color:var(--color-primary)]/5' : 'border-[color:var(--app-border)]',
                                        rolesDisabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:bg-[color:var(--app-surface-muted)]',
                                    )}>
                                        <input type="checkbox" checked={checked} disabled={rolesDisabled}
                                            onChange={() => onSelectedChange(selected.includes(role.id) ? selected.filter((id) => id !== role.id) : [...selected, role.id])}
                                            className="mt-0.5 h-4 w-4 shrink-0 rounded border-[color:var(--app-border-strong)] text-[color:var(--color-primary)] focus:ring-[color:var(--color-primary)]" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block break-words leading-5 text-[color:var(--app-foreground)]">{role.name}</span>
                                            <span className="block text-xs tabular-nums text-[color:var(--app-muted-foreground)]">
                                                {role.guard_name !== 'web' && `${role.guard_name} · `}{t('settings.mfa.usersCount').replace('{{count}}', String(role.users_count))}
                                            </span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    )}
                    {error && <FieldError>{error}</FieldError>}
                </div>
            )}
        </SettingsCard>
    );
}
