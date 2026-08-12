import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

type Category = { id: string; name_en: string; name_am: string | null };
type Organization = { id: string; name_en: string; name_am: string | null };

type Props = {
    categories: Category[];
    organizations: Organization[];
    originLevels: string[];
};

const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';
const errorCls = 'mt-1 text-xs text-red-600 dark:text-red-400';

export default function GrievancesCreate({ categories, organizations, originLevels }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';

    const { data, setData, post, processing, errors } = useForm({
        subject: '',
        description: '',
        category_id: '',
        origin_level: '',
        organization_id: '',
        organization_unit_id: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(route('grievances.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('grievances.submitGrievance')} />}>
            <Head title={t('grievances.submitGrievance')} />

            <div className="mx-auto max-w-2xl">
                <form onSubmit={submit} className="space-y-6 rounded-xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                    <div>
                        <label className={labelCls}>{t('grievances.organization')} *</label>
                        <select className={inputCls} value={data.organization_id} onChange={e => setData('organization_id', e.target.value)}>
                            <option value="">— {t('common.select')} —</option>
                            {organizations.map(o => (
                                <option key={o.id} value={o.id}>{(am ? o.name_am : null) ?? o.name_en}</option>
                            ))}
                        </select>
                        {errors.organization_id && <p className={errorCls}>{errors.organization_id}</p>}
                    </div>

                    <div>
                        <label className={labelCls}>{t('grievances.category')} *</label>
                        <select className={inputCls} value={data.category_id} onChange={e => setData('category_id', e.target.value)}>
                            <option value="">— {t('common.select')} —</option>
                            {categories.map(c => (
                                <option key={c.id} value={c.id}>{(am ? c.name_am : null) ?? c.name_en}</option>
                            ))}
                        </select>
                        {errors.category_id && <p className={errorCls}>{errors.category_id}</p>}
                    </div>

                    <div>
                        <label className={labelCls}>{t('grievances.originLevel')} *</label>
                        <select className={inputCls} value={data.origin_level} onChange={e => setData('origin_level', e.target.value)}>
                            <option value="">— {t('common.select')} —</option>
                            {originLevels.map(l => (
                                <option key={l} value={l}>
                                    {t(`grievances.originLevel${l.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('')}` as never)}
                                </option>
                            ))}
                        </select>
                        {errors.origin_level && <p className={errorCls}>{errors.origin_level}</p>}
                    </div>

                    <div>
                        <label className={labelCls}>{t('grievances.subject')} *</label>
                        <input type="text" className={inputCls} value={data.subject} onChange={e => setData('subject', e.target.value)} />
                        {errors.subject && <p className={errorCls}>{errors.subject}</p>}
                    </div>

                    <div>
                        <label className={labelCls}>{t('grievances.description')} *</label>
                        <textarea rows={5} className={inputCls} value={data.description} onChange={e => setData('description', e.target.value)} />
                        {errors.description && <p className={errorCls}>{errors.description}</p>}
                    </div>

                    <div className="flex justify-end gap-3">
                        <a href={route('grievances.my')} className="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            {t('common.cancel')}
                        </a>
                        <button type="submit" disabled={processing} className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                            {t('grievances.submitGrievance')}
                        </button>
                    </div>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
