import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useState } from 'react';
import type { JSX } from 'react';

type Category = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    is_active: boolean;
};

type Props = { categories: Category[] };

const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'block text-sm font-medium text-gray-700 dark:text-slate-300 mb-1';

function CreateForm({ t }: { t: (k: never) => string }): JSX.Element {
    const { data, setData, post, processing, reset, errors } = useForm({
        name_en: '',
        name_am: '',
        description_en: '',
        description_am: '',
    });

    return (
        <form
            onSubmit={e => {
                e.preventDefault();
                post(route('grievance-categories.store'), { onSuccess: () => reset() });
            }}
            className="rounded-xl border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
        >
            <h3 className="mb-4 text-sm font-semibold text-gray-700 dark:text-slate-300">
                + {t('grievances.category' as never)}
            </h3>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label className={labelCls}>{t('common.nameEn' as never)} *</label>
                    <input
                        type="text"
                        className={inputCls}
                        value={data.name_en}
                        onChange={e => setData('name_en', e.target.value)}
                    />
                    {errors.name_en && <p className="mt-1 text-xs text-red-500">{errors.name_en}</p>}
                </div>
                <div>
                    <label className={labelCls}>{t('common.nameAm' as never)}</label>
                    <input
                        type="text"
                        className={inputCls}
                        value={data.name_am}
                        onChange={e => setData('name_am', e.target.value)}
                    />
                </div>
                <div>
                    <label className={labelCls}>{t('common.description' as never)} ({t('common.english' as never)})</label>
                    <textarea
                        rows={2}
                        className={inputCls}
                        value={data.description_en}
                        onChange={e => setData('description_en', e.target.value)}
                    />
                </div>
                <div>
                    <label className={labelCls}>{t('common.description' as never)} ({t('common.amharic' as never)})</label>
                    <textarea
                        rows={2}
                        className={inputCls}
                        value={data.description_am}
                        onChange={e => setData('description_am', e.target.value)}
                    />
                </div>
            </div>
            <div className="mt-4 flex justify-end">
                <button
                    type="submit"
                    disabled={!data.name_en.trim() || processing}
                    className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                >
                    {t('common.create' as never)}
                </button>
            </div>
        </form>
    );
}

function EditRow({ category, onDone, t, am }: { category: Category; onDone: () => void; t: (k: never) => string; am: boolean }): JSX.Element {
    const { data, setData, patch, processing, errors } = useForm({
        name_en: category.name_en,
        name_am: category.name_am ?? '',
        description_en: category.description_en ?? '',
        description_am: category.description_am ?? '',
        is_active: category.is_active,
    });

    return (
        <tr className="bg-blue-50 dark:bg-slate-800/60">
            <td className="px-4 py-3 font-mono text-xs text-gray-400">{category.code}</td>
            <td className="px-4 py-2">
                <input
                    type="text"
                    className={inputCls}
                    value={data.name_en}
                    onChange={e => setData('name_en', e.target.value)}
                    placeholder={t('common.nameEn' as never)}
                />
                {errors.name_en && <p className="mt-1 text-xs text-red-500">{errors.name_en}</p>}
            </td>
            <td className="px-4 py-2">
                <input
                    type="text"
                    className={inputCls}
                    value={data.name_am}
                    onChange={e => setData('name_am', e.target.value)}
                    placeholder={t('common.nameAm' as never)}
                />
            </td>
            <td className="px-4 py-2">
                <input
                    type="text"
                    className={inputCls}
                    value={am ? data.description_am : data.description_en}
                    onChange={e => am ? setData('description_am', e.target.value) : setData('description_en', e.target.value)}
                />
            </td>
            <td className="px-4 py-2 text-center">
                <input
                    type="checkbox"
                    checked={data.is_active}
                    onChange={e => setData('is_active', e.target.checked)}
                    className="h-4 w-4 rounded border-gray-300 text-blue-600"
                />
            </td>
            <td className="px-4 py-2">
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        disabled={processing}
                        onClick={() => patch(route('grievance-categories.update', category.id), { onSuccess: onDone })}
                        className="rounded bg-blue-600 px-3 py-1 text-xs font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                    >
                        {t('common.save' as never)}
                    </button>
                    <button
                        type="button"
                        onClick={onDone}
                        className="rounded bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-200 dark:bg-slate-700 dark:text-slate-300"
                    >
                        {t('common.cancel' as never)}
                    </button>
                </div>
            </td>
        </tr>
    );
}

export default function CategoriesIndex({ categories }: Props): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';
    const [editingId, setEditingId] = useState<string | null>(null);

    return (
        <AuthenticatedLayout header={<PageHeader title={t('nav.grievanceCategories' as never)} />}>
            <Head title={t('nav.grievanceCategories' as never)} />

            <div className="space-y-6">
                <CreateForm t={t as (k: never) => string} />

                <div className="overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <table className="min-w-full text-left text-sm">
                        <thead className="bg-gray-50 dark:bg-slate-950">
                            <tr>
                                {[
                                    t('common.code' as never),
                                    t('common.nameEn' as never),
                                    t('common.nameAm' as never),
                                    t('common.description' as never),
                                    t('common.status' as never),
                                    '',
                                ].map((h, i) => (
                                    <th
                                        key={i}
                                        className="whitespace-nowrap px-4 py-3 text-xs font-semibold uppercase text-gray-500 dark:text-slate-400"
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100 dark:divide-slate-800">
                            {categories.map(cat =>
                                editingId === cat.id ? (
                                    <EditRow
                                        key={cat.id}
                                        category={cat}
                                        am={am}
                                        t={t as (k: never) => string}
                                        onDone={() => setEditingId(null)}
                                    />
                                ) : (
                                    <tr key={cat.id} className="hover:bg-gray-50 dark:hover:bg-slate-800">
                                        <td className="px-4 py-3 font-mono text-xs text-gray-400">{cat.code}</td>
                                        <td className="px-4 py-3 font-medium text-gray-900 dark:text-slate-100">{cat.name_en}</td>
                                        <td className="px-4 py-3 text-gray-600 dark:text-slate-300">{cat.name_am ?? '-'}</td>
                                        <td className="max-w-xs truncate px-4 py-3 text-gray-500 dark:text-slate-400">
                                            {(am ? cat.description_am : cat.description_en) ?? '-'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-xs font-medium ${cat.is_active ? 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-300' : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'}`}
                                            >
                                                {cat.is_active ? t('common.active' as never) : t('common.inactive' as never)}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3">
                                            <button
                                                type="button"
                                                onClick={() => setEditingId(cat.id)}
                                                className="text-xs text-blue-600 hover:underline dark:text-blue-400"
                                            >
                                                {t('common.edit' as never)}
                                            </button>
                                        </td>
                                    </tr>
                                )
                            )}
                        </tbody>
                    </table>
                    {categories.length === 0 && (
                        <p className="px-4 py-8 text-center text-sm text-gray-400 dark:text-slate-500">
                            {t('common.noRecords' as never)}
                        </p>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
