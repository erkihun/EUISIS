import { useState } from 'react';
import { router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { localizedName } from '@/utils/localizedName';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useConfirm } from '@/hooks/useConfirm';

import { actionLinkClassName, badgeClassName, buttonClassName, cx, inputClassName } from '@/Components/ui';
type OrganizationScope = {
    id: string;
    /** `status` is anything but 'active' for an inactive or archived organization. */
    organization: { id: string; name_en: string; name_am?: string | null; status?: string } | null;
    scope_type: 'self' | 'subtree' | 'citywide' | 'service_provider';
    effective_from: string | null;
    effective_to: string | null;
    is_active: boolean;
};

type OrgOption = {
    id: string;
    code?: string | null;
    name_en: string;
    name_am?: string | null;
    /** 'active' | 'inactive' | … — inactive orgs may not be newly assigned. */
    status?: string;
    type?: { name_en: string; name_am?: string | null } | null;
};

type Props = {
    userId: string;
    scopes: OrganizationScope[];
    organizations: OrgOption[];
    canManage: boolean;
};

type FormState = {
    organization_id: string;
    scope_type: OrganizationScope['scope_type'];
    effective_from: string;
    effective_to: string;
    is_active: boolean;
};

const emptyForm = (): FormState => ({
    organization_id: '',
    scope_type: 'self',
    effective_from: '',
    effective_to: '',
    is_active: true,
});

const inputCls = cx(inputClassName, 'w-full');
const labelCls = 'block text-xs font-medium text-(--app-muted-foreground)';

export default function OrganizationScopesCard({ userId, scopes, organizations, canManage }: Props) {
    const { t, locale } = useLocale();
    const { confirm } = useConfirm();
    const [showForm, setShowForm] = useState(false);
    const [editingId, setEditingId] = useState<string | null>(null);
    const [form, setForm] = useState<FormState>(emptyForm());
    const [orgSearch, setOrgSearch] = useState('');
    /** What the server refused on the last save, by field. */
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    function scopeTypeLabel(st: OrganizationScope['scope_type']): string {
        return t(`users.userOrganizationScopes.scopeTypes.${st}`);
    }

    function orgName(org: { name_en: string; name_am?: string | null } | null | undefined): string {
        if (!org) return '—';
        return localizedName(org.name_en, org.name_am, locale);
    }

    /** Organizations without an explicit status are treated as active (back-compat). */
    function isActiveOrg(org: OrgOption): boolean {
        return (org.status ?? 'active') === 'active';
    }

    /**
     * An inactive organization is still shown (so an existing scope renders), but
     * may only be kept on the scope that already points at it — never assigned anew.
     */
    function isAssignable(org: OrgOption): boolean {
        if (isActiveOrg(org)) return true;
        const editing = editingId ? scopes.find((s) => s.id === editingId) : null;
        return editing?.organization?.id === org.id;
    }

    function filteredOrgs(): OrgOption[] {
        const q = orgSearch.trim().toLowerCase();
        if (q === '') return organizations;

        return organizations.filter((o) =>
            `${o.code ?? ''} ${o.name_en} ${o.name_am ?? ''}`.toLowerCase().includes(q),
        );
    }

    function openAdd() {
        setForm(emptyForm());
        setOrgSearch('');
        setErrors({});
        setEditingId(null);
        setShowForm(true);
    }

    function openEdit(scope: OrganizationScope) {
        setForm({
            organization_id: scope.organization?.id ?? '',
            scope_type: scope.scope_type,
            effective_from: scope.effective_from ?? '',
            effective_to: scope.effective_to ?? '',
            is_active: scope.is_active,
        });
        // Keep the search box empty so the full list stays browsable; the current
        // organization is highlighted as selected in the list instead.
        setOrgSearch('');
        setErrors({});
        setEditingId(scope.id);
        setShowForm(true);
    }

    function cancelForm() {
        setShowForm(false);
        setEditingId(null);
        setForm(emptyForm());
        setOrgSearch('');
        setErrors({});
    }

    function submitForm() {
        const base = {
            scope_type: form.scope_type,
            effective_from: form.effective_from || null,
            effective_to: form.effective_to || null,
            is_active: form.is_active,
        };

        const payload =
            form.scope_type !== 'citywide'
                ? { ...base, organization_id: form.organization_id }
                : base;

        // A refused save used to look like nothing happened: the form stayed
        // open with no message. The reasons are now shown under their fields.
        const options = {
            preserveScroll: true,
            onStart: () => { setSaving(true); setErrors({}); },
            onSuccess: cancelForm,
            onError: (refused: Record<string, string>) => setErrors(refused),
            onFinish: () => setSaving(false),
        };

        if (editingId) {
            router.put(route('users.organization-scopes.update', { user: userId, scope: editingId }), payload, options);
        } else {
            router.post(route('users.organization-scopes.store', { user: userId }), payload, options);
        }
    }

    const fieldError = (field: string) => errors[field]
        ? <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors[field]}</p>
        : null;
    // Anything the server refused that has no field of its own on this form.
    const otherErrors = Object.entries(errors)
        .filter(([field]) => !['organization_id', 'scope_type', 'effective_from', 'effective_to', 'is_active'].includes(field))
        .map(([, message]) => message);

    async function removeScope(scopeId: string) {
        const result = await confirm({ title: t('users.userOrganizationScopes.confirmRemove'), variant: 'danger' });
        if (!result.confirmed) return;
        router.delete(route('users.organization-scopes.destroy', { user: userId, scope: scopeId }), {
            preserveScroll: true,
        });
    }

    return (
        <div className="rounded-panel border border-(--app-border) bg-(--app-surface) p-6 xl:sticky xl:top-4">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-(--app-foreground)">
                    {t('users.userOrganizationScopes.title')}
                </h3>
                {canManage && !showForm && (
                    <button
                        type="button"
                        onClick={openAdd}
                        className={buttonClassName({ variant: 'primary', size: 'sm' })}
                    >
                        {t('users.userOrganizationScopes.addScope')}
                    </button>
                )}
            </div>

            {/* This card lives in the page's narrow right column, so the list and the
                add/edit form stack vertically rather than sitting side by side. */}
            <div className="space-y-4">
                <div className="min-w-0">
            {scopes.length === 0 && (
                <p className="text-sm text-(--app-muted-foreground)">
                    {t('users.userOrganizationScopes.noScopes')}
                </p>
            )}

            {/* One block per scope, not a table: six columns do not fit this
                narrow column, and the Edit and Remove actions were pushed out
                of sight. */}
            {scopes.length > 0 && (
                <ul className="divide-y divide-(--app-border) rounded-lg border border-(--app-border)">
                    {scopes.map((scope) => {
                        const organizationInactive = scope.scope_type !== 'citywide'
                            && scope.organization != null
                            && (scope.organization.status ?? 'active') !== 'active';

                        return (
                            <li key={scope.id} className={cx('px-3 py-3', editingId === scope.id && 'bg-(--app-surface-muted)')}>
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="break-words text-sm font-medium text-(--app-foreground)">
                                            {scope.scope_type === 'citywide'
                                                ? scopeTypeLabel(scope.scope_type)
                                                : orgName(scope.organization)}
                                        </p>
                                        {scope.scope_type !== 'citywide' && (
                                            <p className="mt-0.5 text-xs text-(--app-muted-foreground)">
                                                {scopeTypeLabel(scope.scope_type)}
                                            </p>
                                        )}
                                    </div>
                                    <span className={cx(badgeClassName(scope.is_active ? 'success' : 'neutral'), 'shrink-0')}>
                                        {scope.is_active ? t('common.active') : t('common.inactive')}
                                    </span>
                                </div>

                                {organizationInactive && (
                                    <p className="mt-1.5">
                                        <span className={badgeClassName('warning')}>
                                            {t('users.userOrganizationScopes.inactiveOrganization')}
                                        </span>
                                    </p>
                                )}

                                <dl className="mt-2 grid grid-cols-2 gap-2 text-xs">
                                    <div>
                                        <dt className="text-(--app-muted-foreground)">{t('users.userOrganizationScopes.effectiveFrom')}</dt>
                                        <dd className="mt-0.5 text-(--app-foreground)"><LocalizedDateDisplay value={scope.effective_from} /></dd>
                                    </div>
                                    <div>
                                        <dt className="text-(--app-muted-foreground)">{t('users.userOrganizationScopes.effectiveTo')}</dt>
                                        <dd className="mt-0.5 text-(--app-foreground)"><LocalizedDateDisplay value={scope.effective_to} /></dd>
                                    </div>
                                </dl>

                                {canManage && (
                                    <div className="mt-2 flex items-center gap-4">
                                        <button
                                            type="button"
                                            onClick={() => openEdit(scope)}
                                            className={actionLinkClassName()}
                                        >
                                            {t('common.edit')}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => removeScope(scope.id)}
                                            className={actionLinkClassName({ tone: 'danger' })}
                                        >
                                            {t('users.userOrganizationScopes.remove')}
                                        </button>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
                </div>

            {showForm && (
                <div className="rounded-lg border border-(--app-border) bg-(--app-surface-muted) p-4">
                    <p className="mb-3 text-sm font-semibold text-(--app-foreground)">
                        {editingId
                            ? t('users.userOrganizationScopes.editScope')
                            : t('users.userOrganizationScopes.addScope')}
                    </p>
                    <div className="space-y-3">
                        <div>
                            <label className={labelCls}>{t('users.userOrganizationScopes.scopeType')}</label>
                            <div className="mt-1">
                                <select
                                    className={inputCls}
                                    value={form.scope_type}
                                    onChange={(e) =>
                                        setForm((f) => ({
                                            ...f,
                                            scope_type: e.target.value as OrganizationScope['scope_type'],
                                            organization_id: e.target.value === 'citywide' ? '' : f.organization_id,
                                        }))
                                    }
                                >
                                    {(['self', 'subtree', 'citywide', 'service_provider'] as const).map((st) => (
                                        <option key={st} value={st}>
                                            {scopeTypeLabel(st)}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            {fieldError('scope_type')}
                        </div>

                        {form.scope_type !== 'citywide' && (
                            <div>
                                <label className={labelCls}>{t('users.userOrganizationScopes.organization')}</label>
                                <p className="mt-0.5 text-xs text-(--app-muted-foreground)">
                                    {t('users.userOrganizationScopes.selectOrganizations')}
                                </p>

                                {organizations.length === 0 ? (
                                    <p className="mt-2 rounded-lg border border-dashed border-(--app-border) px-3 py-4 text-center text-sm text-(--app-muted-foreground)">
                                        {t('users.userOrganizationScopes.noOrganizations')}
                                    </p>
                                ) : (
                                    <div className="mt-1 space-y-1">
                                        <input
                                            className={inputCls}
                                            placeholder={t('users.userOrganizationScopes.searchOrganizations')}
                                            value={orgSearch}
                                            onChange={(e) => setOrgSearch(e.target.value)}
                                        />

                                        {/* The list is always visible — the user must be able to
                                            browse organizations without guessing a search term. */}
                                        <ul className="max-h-56 overflow-y-auto rounded-lg border border-(--app-border) bg-(--app-surface)">
                                            {filteredOrgs().length === 0 ? (
                                                <li className="px-3 py-4 text-center text-sm text-(--app-muted-foreground)">
                                                    {t('users.userOrganizationScopes.noOrganizationsMatch')}
                                                </li>
                                            ) : (
                                                filteredOrgs().map((o) => {
                                                    const selected = form.organization_id === o.id;
                                                    // An inactive org may stay on the scope it already
                                                    // has, but may not be picked for a different one.
                                                    const assignable = isAssignable(o);

                                                    return (
                                                        <li key={o.id}>
                                                            <button
                                                                type="button"
                                                                disabled={!assignable}
                                                                title={!assignable ? t('users.userOrganizationScopes.inactiveCannotAssign') : undefined}
                                                                className={`flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition-colors ${
                                                                    selected
                                                                        ? 'bg-blue-50 dark:bg-blue-900/20'
                                                                        : assignable
                                                                            ? 'hover:bg-(--app-surface-muted)'
                                                                            : 'cursor-not-allowed opacity-50'
                                                                }`}
                                                                onClick={() => {
                                                                    if (!assignable) return;
                                                                    setForm((f) => ({ ...f, organization_id: o.id }));
                                                                }}
                                                            >
                                                                <span className="min-w-0">
                                                                    <span className="flex flex-wrap items-center gap-1.5">
                                                                        {o.code && (
                                                                            <span className="font-mono text-xs text-(--app-muted-foreground)">
                                                                                {o.code}
                                                                            </span>
                                                                        )}
                                                                        <span className="truncate font-medium text-(--app-foreground)">
                                                                            {orgName(o)}
                                                                        </span>
                                                                    </span>
                                                                    <span className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                                                        {o.type && (
                                                                            <span className="text-xs text-(--app-muted-foreground)">
                                                                                {localizedName(o.type.name_en, o.type.name_am, locale)}
                                                                            </span>
                                                                        )}
                                                                        {!isActiveOrg(o) && (
                                                                            <span className={badgeClassName('warning')}>
                                                                                {t('users.userOrganizationScopes.inactiveOrganization')}
                                                                            </span>
                                                                        )}
                                                                    </span>
                                                                </span>
                                                                {selected && (
                                                                    <span className="shrink-0 text-xs font-semibold text-primary dark:text-primary">
                                                                        ✓
                                                                    </span>
                                                                )}
                                                            </button>
                                                        </li>
                                                    );
                                                })
                                            )}
                                        </ul>
                                    </div>
                                )}

                                {fieldError('organization_id')}
                                {form.scope_type === 'self' && (
                                    <p className="mt-1 text-xs text-(--app-muted-foreground)">
                                        {t('users.userOrganizationScopes.selfHelperText')}
                                    </p>
                                )}
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            <div>
                                <label className={labelCls}>{t('users.userOrganizationScopes.effectiveFrom')}</label>
                                <div className="mt-1">
                                    <LocalizedDatePicker
                                        className={inputCls}
                                        value={form.effective_from}
                                        onChange={(iso) => setForm((f) => ({ ...f, effective_from: iso }))}
                                    />
                                </div>
                                {fieldError('effective_from')}
                            </div>
                            <div>
                                <label className={labelCls}>{t('users.userOrganizationScopes.effectiveTo')}</label>
                                <div className="mt-1">
                                    <LocalizedDatePicker
                                        className={inputCls}
                                        value={form.effective_to}
                                        onChange={(iso) => setForm((f) => ({ ...f, effective_to: iso }))}
                                    />
                                </div>
                                {fieldError('effective_to')}
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                id="scope-is-active"
                                className="h-4 w-4 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                                checked={form.is_active}
                                onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))}
                            />
                            <label htmlFor="scope-is-active" className="text-sm text-(--app-foreground)">
                                {t('users.userOrganizationScopes.isActive')}
                            </label>
                        </div>
                        {fieldError('is_active')}

                        {otherErrors.length > 0 && (
                            <div role="alert" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                                {otherErrors.map((message) => <p key={message}>{message}</p>)}
                            </div>
                        )}
                    </div>

                    <div className="mt-4 flex items-center justify-end gap-3">
                        <button
                            type="button"
                            onClick={cancelForm}
                            className="rounded-lg px-3 py-1.5 text-sm font-medium text-(--app-foreground) hover:bg-(--app-surface-muted)"
                        >
                            {t('users.userOrganizationScopes.cancel')}
                        </button>
                        <button
                            type="button"
                            onClick={submitForm}
                            disabled={saving || (form.scope_type !== 'citywide' && form.organization_id === '')}
                            className={buttonClassName({ variant: 'primary', size: 'sm' })}
                        >
                            {saving ? t('common.saving') : t('users.userOrganizationScopes.save')}
                        </button>
                    </div>
                </div>
            )}
            </div>
        </div>
    );
}
