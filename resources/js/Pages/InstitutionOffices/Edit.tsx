import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import CodeRuleField from '@/Components/code-rules/CodeRuleField';
import { useLocale } from '@/hooks/useLocale';

import { cx, inputClassName, Button, FieldError, FormLabel, Input } from '@/Components/ui';
interface InstitutionOption {
    id: string;
    name_en: string;
    name_am: string | null;
    code: string;
}

interface ParentOfficeOption {
    id: string;
    name_en: string | null;
    name_am: string | null;
    office_code: string;
    office_level: string;
}

interface LevelOption {
    value: string;
    label: string;
}

interface OfficeData {
    id: string;
    institution_id: string;
    geographic_organization_id: string | null;
    parent_office_id: string | null;
    office_level: string;
    office_code: string;
    name_en: string | null;
    name_am: string | null;
    short_name_en: string | null;
    short_name_am: string | null;
    assigned_scope_type: string;
    is_head_office: boolean;
    status: string;
    opened_on: string | null;
    closed_on: string | null;
    address_en: string | null;
    address_am: string | null;
    phone_number: string | null;
    email: string | null;
    notes: string | null;
}

interface Props {
    office: OfficeData;
    institutions: InstitutionOption[];
    parentOfficeOptions: ParentOfficeOption[];
    geographicOrgs: InstitutionOption[];
    levelOptions: LevelOption[];
    statusOptions: LevelOption[];
}

export default function InstitutionOfficesEdit({
    office,
    institutions,
    parentOfficeOptions,
    geographicOrgs,
    levelOptions,
    statusOptions,
}: Props) {
    const { t } = useLocale();

    const { data, setData, patch, processing, errors } = useForm({
        institution_id: office.institution_id,
        geographic_organization_id: office.geographic_organization_id ?? '',
        parent_office_id: office.parent_office_id ?? '',
        office_level: office.office_level,
        office_code: office.office_code,
        name_en: office.name_en ?? '',
        name_am: office.name_am ?? '',
        short_name_en: office.short_name_en ?? '',
        short_name_am: office.short_name_am ?? '',
        assigned_scope_type: (office.assigned_scope_type ?? 'self') as 'self' | 'subtree',
        is_head_office: office.is_head_office,
        status: office.status,
        opened_on: office.opened_on ?? '',
        closed_on: office.closed_on ?? '',
        address_en: office.address_en ?? '',
        address_am: office.address_am ?? '',
        phone_number: office.phone_number ?? '',
        email: office.email ?? '',
        notes: office.notes ?? '',
    });

    const inputCls = cx(inputClassName, 'w-full');

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        patch(route('institution-offices.update', office.id));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('institutionOffices.edit')}
                    backHref={route('institution-offices.show', office.id)}
                />
            }
        >
            <Head title={t('institutionOffices.edit')} />

            <form onSubmit={handleSubmit} className="mx-auto max-w-3xl space-y-6">
                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                    <h3 className="mb-4 font-semibold text-[color:var(--app-foreground)]">
                        {t('institutionOffices.institution')}
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <FormLabel htmlFor="institution_id">{t('institutionOffices.institution')}</FormLabel>
                            <select
                                id="institution_id"
                                value={data.institution_id}
                                onChange={(e) => setData('institution_id', e.target.value)}
                                className={inputCls}
                                required
                            >
                                <option value="">—</option>
                                {institutions.map((inst) => (
                                    <option key={inst.id} value={inst.id}>
                                        {inst.name_en} ({inst.code})
                                    </option>
                                ))}
                            </select>
                            <FieldError>{errors.institution_id}</FieldError>
                        </div>

                        <div>
                            <FormLabel htmlFor="office_level">{t('institutionOffices.officeLevel')}</FormLabel>
                            <select
                                id="office_level"
                                value={data.office_level}
                                onChange={(e) => setData('office_level', e.target.value)}
                                className={inputCls}
                            >
                                {levelOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                            <FieldError>{errors.office_level}</FieldError>
                        </div>

                        <div>
                            <CodeRuleField
                                entityType="institution_office"
                                context={{
                                    institution_id: data.institution_id || undefined,
                                    office_level: data.office_level || undefined,
                                }}
                                value={data.office_code}
                                onChange={(v) => setData('office_code', v)}
                                fieldName="office_code"
                                label={t('institutionOffices.officeCode')}
                                canManualOverride={false}
                                existingCode={office.office_code}
                                preserveExistingCodeOnEdit
                                error={errors.office_code}
                            />
                        </div>
                    </div>
                </section>

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                    <h3 className="mb-4 font-semibold text-[color:var(--app-foreground)]">
                        {t('institutionOffices.officeName')}
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <FormLabel htmlFor="name_en">{t('institutionOffices.nameEn')}</FormLabel>
                            <Input
                                id="name_en"
                                value={data.name_en}
                                onChange={(e) => setData('name_en', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.name_en}</FieldError>
                        </div>
                        <div>
                            <FormLabel htmlFor="name_am">{t('institutionOffices.nameAm')}</FormLabel>
                            <Input
                                id="name_am"
                                value={data.name_am}
                                onChange={(e) => setData('name_am', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.name_am}</FieldError>
                        </div>
                        <div>
                            <FormLabel htmlFor="short_name_en">{t('institutionOffices.shortNameEn')}</FormLabel>
                            <Input
                                id="short_name_en"
                                value={data.short_name_en}
                                onChange={(e) => setData('short_name_en', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.short_name_en}</FieldError>
                        </div>
                        <div>
                            <FormLabel htmlFor="short_name_am">{t('institutionOffices.shortNameAm')}</FormLabel>
                            <Input
                                id="short_name_am"
                                value={data.short_name_am}
                                onChange={(e) => setData('short_name_am', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.short_name_am}</FieldError>
                        </div>
                    </div>
                </section>

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                    <h3 className="mb-4 font-semibold text-[color:var(--app-foreground)]">
                        {t('institutionOffices.parentOffice')}
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="sm:col-span-2 flex items-center gap-3">
                            <input
                                type="checkbox"
                                id="is_head_office"
                                checked={data.is_head_office}
                                onChange={(e) => {
                                    setData('is_head_office', e.target.checked);
                                    if (e.target.checked) setData('parent_office_id', '');
                                }}
                                className="h-4 w-4 rounded border-[color:var(--app-border-strong)]"
                            />
                            <label htmlFor="is_head_office" className="text-sm font-medium text-[color:var(--app-foreground)]">
                                {t('institutionOffices.isHeadOffice')}
                            </label>
                        </div>

                        {!data.is_head_office && (
                            <div className="sm:col-span-2">
                                <FormLabel htmlFor="parent_office_id">{t('institutionOffices.parentOffice')}</FormLabel>
                                <select
                                    id="parent_office_id"
                                    value={data.parent_office_id}
                                    onChange={(e) => setData('parent_office_id', e.target.value)}
                                    className={inputCls}
                                >
                                    <option value="">{t('institutionOffices.noParent')}</option>
                                    {parentOfficeOptions.map((opt) => (
                                        <option key={opt.id} value={opt.id}>
                                            {opt.name_en ?? opt.office_code} [{opt.office_level}]
                                        </option>
                                    ))}
                                </select>
                                <FieldError>{errors.parent_office_id}</FieldError>
                            </div>
                        )}

                        <div className="sm:col-span-2">
                            <FormLabel htmlFor="geographic_organization_id">{t('institutionOffices.geographicArea')}</FormLabel>
                            <select
                                id="geographic_organization_id"
                                value={data.geographic_organization_id}
                                onChange={(e) => setData('geographic_organization_id', e.target.value)}
                                className={inputCls}
                            >
                                <option value="">—</option>
                                {geographicOrgs.map((org) => (
                                    <option key={org.id} value={org.id}>
                                        {org.name_en} ({org.code})
                                    </option>
                                ))}
                            </select>
                            <FieldError>{errors.geographic_organization_id}</FieldError>
                        </div>
                    </div>
                </section>

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                    <h3 className="mb-4 font-semibold text-[color:var(--app-foreground)]">
                        {t('institutionOffices.status')}
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <FormLabel htmlFor="status">{t('institutionOffices.status')}</FormLabel>
                            <select
                                id="status"
                                value={data.status}
                                onChange={(e) => setData('status', e.target.value)}
                                className={inputCls}
                            >
                                {statusOptions.map((opt) => (
                                    <option key={opt.value} value={opt.value}>
                                        {opt.label}
                                    </option>
                                ))}
                            </select>
                            <FieldError>{errors.status}</FieldError>
                        </div>
                        <div>
                            <FormLabel htmlFor="assigned_scope_type">{t('institutionOffices.assignedScopeType')}</FormLabel>
                            <select
                                id="assigned_scope_type"
                                value={data.assigned_scope_type}
                                onChange={(e) => setData('assigned_scope_type', e.target.value as 'self' | 'subtree')}
                                className={inputCls}
                            >
                                <option value="self">{t('institutionOffices.scopeSelf')}</option>
                                <option value="subtree">{t('institutionOffices.scopeSubtree')}</option>
                            </select>
                            <FieldError>{errors.assigned_scope_type}</FieldError>
                        </div>
                        <div>
                            <FormLabel htmlFor="opened_on">{t('institutionOffices.openedOn')}</FormLabel>
                            <Input
                                type="date"
                                id="opened_on"
                                value={data.opened_on}
                                onChange={(e) => setData('opened_on', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.opened_on}</FieldError>
                        </div>
                        <div>
                            <FormLabel htmlFor="closed_on">{t('institutionOffices.closedOn')}</FormLabel>
                            <Input
                                type="date"
                                id="closed_on"
                                value={data.closed_on}
                                onChange={(e) => setData('closed_on', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.closed_on}</FieldError>
                        </div>
                    </div>
                </section>

                <section className="rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6">
                    <h3 className="mb-4 font-semibold text-[color:var(--app-foreground)]">
                        {t('institutionOffices.address')}
                    </h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <FormLabel htmlFor="address_en">{t('institutionOffices.addressEn')}</FormLabel>
                            <Input
                                id="address_en"
                                value={data.address_en}
                                onChange={(e) => setData('address_en', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.address_en}</FieldError>
                        </div>
                        <div>
                            <FormLabel htmlFor="address_am">{t('institutionOffices.addressAm')}</FormLabel>
                            <Input
                                id="address_am"
                                value={data.address_am}
                                onChange={(e) => setData('address_am', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.address_am}</FieldError>
                        </div>
                        <div>
                            <FormLabel htmlFor="phone_number">{t('institutionOffices.phone')}</FormLabel>
                            <Input
                                id="phone_number"
                                value={data.phone_number}
                                onChange={(e) => setData('phone_number', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.phone_number}</FieldError>
                        </div>
                        <div>
                            <FormLabel htmlFor="email">{t('institutionOffices.email')}</FormLabel>
                            <Input
                                type="email"
                                id="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                className="w-full"
                            />
                            <FieldError>{errors.email}</FieldError>
                        </div>
                        <div className="sm:col-span-2">
                            <FormLabel htmlFor="notes">{t('institutionOffices.notes')}</FormLabel>
                            <textarea
                                id="notes"
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                rows={3}
                                className={inputCls}
                            />
                            <FieldError>{errors.notes}</FieldError>
                        </div>
                    </div>
                </section>

                <div className="flex items-center justify-end gap-3">
                    <Link
                        href={route('institution-offices.show', office.id)}
                        className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)] hover:bg-[color:var(--app-surface-muted)]"
                    >
                        {t('common.cancel')}
                    </Link>
                    <Button disabled={processing}>{t('common.save')}</Button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
