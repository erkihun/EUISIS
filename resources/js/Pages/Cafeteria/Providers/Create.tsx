import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import InputLabel from '@/Components/InputLabel';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, ReactNode } from 'react';
import { useLocale } from '@/hooks/useLocale';

type OrgOption = { id: string; name_en: string; name_am: string | null; code: string };

export default function ProvidersCreate({ organizations }: { organizations: OrgOption[] }) {
    const { t, locale } = useLocale();
    const form = useForm({
        code: '',
        name_en: '',
        name_am: '',
        organization_id: '',
        assigned_scope_type: 'self' as 'self' | 'subtree',
        contact_person: '',
        phone_number: '',
        email: '',
        location: '',
        is_active: true,
    });

    const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-60';

    function orgLabel(o: OrgOption): string {
        return (locale === 'am' && o.name_am) ? o.name_am : o.name_en;
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post(route('cafeteria.providers.store'));
    }

    return (
        <AuthenticatedLayout
            header={<PageHeader title={t('cafeteria.addProvider')} backHref={route('cafeteria.providers.index')} />}
        >
            <form onSubmit={submit} className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div className="border-b border-gray-200 px-6 py-4 dark:border-slate-800">
                    <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100">{t('cafeteria.provider')}</h2>
                </div>
                <div className="grid gap-5 p-6 md:grid-cols-2">
                    <Field label={t('cafeteria.providerCode')} error={form.errors.code}>
                        <input className={inputCls} value={form.data.code} onChange={e => form.setData('code', e.target.value.toUpperCase())} required />
                    </Field>
                    <Field label={t('cafeteria.nameEn')} error={form.errors.name_en}>
                        <input className={inputCls} value={form.data.name_en} onChange={e => form.setData('name_en', e.target.value)} required />
                    </Field>
                    <Field label={t('cafeteria.nameAm')} error={form.errors.name_am}>
                        <input className={inputCls} value={form.data.name_am} onChange={e => form.setData('name_am', e.target.value)} />
                    </Field>

                    {/* Institution assignment */}
                    <Field label={t('cafeteria.assignedInstitution')} error={form.errors.organization_id}>
                        <select
                            className={inputCls}
                            value={form.data.organization_id}
                            onChange={e => form.setData('organization_id', e.target.value)}
                            required
                        >
                            <option value="">{t('common.select')}…</option>
                            {organizations.map(o => (
                                <option key={o.id} value={o.id}>{orgLabel(o)} ({o.code})</option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('cafeteria.assignmentScope')} error={form.errors.assigned_scope_type}>
                        <select
                            className={inputCls}
                            value={form.data.assigned_scope_type}
                            onChange={e => form.setData('assigned_scope_type', e.target.value as 'self' | 'subtree')}
                        >
                            <option value="self">{t('cafeteria.assignmentScopeSelf')}</option>
                            <option value="subtree">{t('cafeteria.assignmentScopeSubtree')}</option>
                        </select>
                        <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{t('cafeteria.institutionScopeHelp')}</p>
                    </Field>

                    <Field label={t('cafeteria.contactPerson')} error={form.errors.contact_person}>
                        <input className={inputCls} value={form.data.contact_person} onChange={e => form.setData('contact_person', e.target.value)} />
                    </Field>
                    <Field label={t('cafeteria.phoneNumber')} error={form.errors.phone_number}>
                        <input className={inputCls} value={form.data.phone_number} onChange={e => form.setData('phone_number', e.target.value)} />
                    </Field>
                    <Field label={t('common.email')} error={form.errors.email}>
                        <input type="email" className={inputCls} value={form.data.email} onChange={e => form.setData('email', e.target.value)} />
                    </Field>
                    <div className="md:col-span-2">
                        <Field label={t('cafeteria.location')} error={form.errors.location}>
                            <input className={inputCls} value={form.data.location} onChange={e => form.setData('location', e.target.value)} />
                        </Field>
                    </div>
                </div>
                <div className="flex justify-end gap-3 border-t border-gray-200 px-6 py-4 dark:border-slate-800">
                    <Link href={route('cafeteria.providers.index')} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                        {t('common.cancel')}
                    </Link>
                    <button type="submit" disabled={form.processing} className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60">
                        {t('common.save')}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}

function Field({ children, error, label }: { children: ReactNode; error?: string; label: string }) {
    return (
        <div className="space-y-1.5">
            <InputLabel value={label} />
            {children}
            {error && <p className="text-xs text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}
