import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type OrgOption = { id: string; name_en: string; name_am: string | null; code: string };
type Branch = {
    id: string; code: string; name_en: string; name_am: string | null;
    organization_id: string | null; location: string | null;
    contact_person: string | null; phone_number: string | null; is_active: boolean;
};

const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';

export default function EditBranch({
    provider,
    branch,
    organizations,
}: {
    provider: { id: string; code: string; name_en: string };
    branch: Branch;
    organizations: OrgOption[];
}) {
    const { t } = useLocale();

    const form = useForm({
        code: branch.code,
        name_en: branch.name_en,
        name_am: branch.name_am ?? '',
        organization_id: branch.organization_id ?? '',
        location: branch.location ?? '',
        contact_person: branch.contact_person ?? '',
        phone_number: branch.phone_number ?? '',
        is_active: branch.is_active,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        form.patch(route('cafeteria.providers.branches.update', { cafeteriaProvider: provider.id, branch: branch.id }));
    }

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('cafeteria.editBranch')}
                    backHref={route('cafeteria.providers.show', provider.id)}
                    description={`${provider.name_en} · ${branch.code}`}
                />
            }
        >
            <div className="mx-auto max-w-2xl">
                <form onSubmit={submit} className="space-y-5">
                    <div className="rounded-panel border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900 space-y-4">

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className={labelCls}>{t('cafeteria.branchCode')} *</label>
                                <input className={inputCls} value={form.data.code} onChange={e => form.setData('code', e.target.value)} required />
                                {form.errors.code && <p className="mt-1 text-xs text-red-600">{form.errors.code}</p>}
                            </div>
                            <div>
                                <label className={labelCls}>{t('common.organization')}</label>
                                <select className={inputCls} value={form.data.organization_id} onChange={e => form.setData('organization_id', e.target.value)}>
                                    <option value="">{t('common.select')}</option>
                                    {organizations.map(o => (
                                        <option key={o.id} value={o.id}>{o.name_en} · {o.code}</option>
                                    ))}
                                </select>
                                {form.errors.organization_id && <p className="mt-1 text-xs text-red-600">{form.errors.organization_id}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className={labelCls}>{t('cafeteria.nameEn')} *</label>
                                <input className={inputCls} value={form.data.name_en} onChange={e => form.setData('name_en', e.target.value)} required />
                                {form.errors.name_en && <p className="mt-1 text-xs text-red-600">{form.errors.name_en}</p>}
                            </div>
                            <div>
                                <label className={labelCls}>{t('cafeteria.nameAm')}</label>
                                <input className={inputCls} value={form.data.name_am} onChange={e => form.setData('name_am', e.target.value)} dir="auto" />
                                {form.errors.name_am && <p className="mt-1 text-xs text-red-600">{form.errors.name_am}</p>}
                            </div>
                        </div>

                        <div>
                            <label className={labelCls}>{t('cafeteria.location')}</label>
                            <input className={inputCls} value={form.data.location} onChange={e => form.setData('location', e.target.value)} />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className={labelCls}>{t('cafeteria.contactPerson')}</label>
                                <input className={inputCls} value={form.data.contact_person} onChange={e => form.setData('contact_person', e.target.value)} />
                            </div>
                            <div>
                                <label className={labelCls}>{t('cafeteria.phoneNumber')}</label>
                                <input className={inputCls} value={form.data.phone_number} onChange={e => form.setData('phone_number', e.target.value)} />
                            </div>
                        </div>

                        <div>
                            <label className={labelCls}>{t('cafeteria.isActive')}</label>
                            <select className={inputCls} value={form.data.is_active ? '1' : '0'} onChange={e => form.setData('is_active', e.target.value === '1')}>
                                <option value="1">{t('common.active')}</option>
                                <option value="0">{t('common.inactive')}</option>
                            </select>
                        </div>
                    </div>

                    <div className="flex justify-end gap-3">
                        <a href={route('cafeteria.providers.show', provider.id)} className="rounded-lg px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-slate-300 dark:hover:bg-slate-800">
                            {t('common.cancel')}
                        </a>
                        <button type="submit" disabled={form.processing} className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60">
                            {form.processing ? t('common.saving') : t('common.saveChanges')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
