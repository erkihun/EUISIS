import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import { inputCls, labelCls, panelCls, primaryBtn, secondaryBtn } from '@/Components/fieldWork/helpers';
import { useLocale } from '@/hooks/useLocale';
import { Head, useForm } from '@inertiajs/react';
import { useState, type FormEvent, type JSX } from 'react';

type FieldWorkTypeRow = {
    id: string;
    code: string;
    name_en: string;
    name_am: string | null;
    description_en: string | null;
    description_am: string | null;
    is_active: boolean;
    sort_order: number;
    requests_count: number;
};

type FormData = { code: string; name_en: string; name_am: string; description_en: string; description_am: string; is_active: boolean; sort_order: string };

const blank: FormData = { code: '', name_en: '', name_am: '', description_en: '', description_am: '', is_active: true, sort_order: '0' };

/** Field Work Types catalog: add, rename, (de)activate. Never delete. */
export default function FieldWorkTypes({ types }: { types: FieldWorkTypeRow[] }): JSX.Element {
    const { t, locale } = useLocale();
    const [editing, setEditing] = useState<string | 'new' | null>(null);
    const form = useForm<FormData>(blank);

    function open(row: FieldWorkTypeRow | null) {
        form.clearErrors();
        form.setData(row ? {
            code: row.code, name_en: row.name_en, name_am: row.name_am ?? '', description_en: row.description_en ?? '', description_am: row.description_am ?? '',
            is_active: row.is_active, sort_order: String(row.sort_order),
        } : blank);
        setEditing(row ? row.id : 'new');
    }

    function save(event: FormEvent) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setEditing(null) };
        if (editing === 'new') form.post(route('field-work.types.store'), options);
        else if (editing) form.put(route('field-work.types.update', editing), options);
    }

    const field = (key: keyof FormData, label: string, multiline = false) => (
        <div>
            <label htmlFor={`type-${key}`} className={labelCls}>{label}</label>
            {multiline
                ? <textarea id={`type-${key}`} rows={2} className={inputCls} value={String(form.data[key])} onChange={(e) => form.setData(key, e.target.value as never)} />
                : <input id={`type-${key}`} className={inputCls} value={String(form.data[key])} onChange={(e) => form.setData(key, e.target.value as never)} />}
            {form.errors[key] && <p className="mt-1 text-xs text-red-700 dark:text-red-400">{form.errors[key]}</p>}
        </div>
    );

    return (
        <AuthenticatedLayout header={<PageHeader title={t('fieldWork.management.typesTitle')} description={t('fieldWork.management.typesDescription')} actions={<button type="button" className={primaryBtn} onClick={() => open(null)}>{t('fieldWork.types.add')}</button>} />}>
            <Head title={t('fieldWork.management.typesTitle')} />
            <div className="space-y-3">
                {editing !== null && (
                    <form onSubmit={save} className={`${panelCls} grid gap-3 p-4 sm:grid-cols-2`}>
                        <h2 className="text-sm font-semibold text-gray-900 sm:col-span-2 dark:text-slate-100">{editing === 'new' ? t('fieldWork.types.add') : t('fieldWork.types.edit')}</h2>
                        {field('code', t('fieldWork.types.code'))}
                        {field('sort_order', t('fieldWork.types.sortOrder'))}
                        {field('name_en', t('fieldWork.types.nameEn'))}
                        {field('name_am', t('fieldWork.types.nameAm'))}
                        {field('description_en', t('fieldWork.types.descriptionEn'), true)}
                        {field('description_am', t('fieldWork.types.descriptionAm'), true)}
                        <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                            <input type="checkbox" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />
                            {t('fieldWork.types.active')}
                        </label>
                        <div className="flex gap-2 sm:col-span-2">
                            <button type="submit" className={primaryBtn} disabled={form.processing}>{t('fieldWork.types.save')}</button>
                            <button type="button" className={secondaryBtn} onClick={() => setEditing(null)}>{t('fieldWork.actions.close')}</button>
                        </div>
                    </form>
                )}

                {types.length === 0 ? (
                    <p className={`${panelCls} p-4 text-sm text-gray-500 dark:text-slate-400`}>{t('fieldWork.types.empty')}</p>
                ) : (
                    <ul className={`${panelCls} divide-y divide-gray-100 dark:divide-slate-800`}>
                        {types.map((row) => (
                            <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-gray-900 dark:text-slate-100">
                                        {locale === 'am' && row.name_am ? row.name_am : row.name_en}
                                        <span className="ms-2 text-xs font-normal text-gray-500 dark:text-slate-400">{row.code}</span>
                                    </p>
                                    <p className="text-xs text-gray-500 dark:text-slate-400">{t('fieldWork.types.usage')}: {row.requests_count}</p>
                                </div>
                                <div className="flex items-center gap-2">
                                    <StatusBadge status={row.is_active ? 'active' : 'inactive'} label={row.is_active ? t('fieldWork.types.active') : t('fieldWork.types.inactive')} />
                                    <button type="button" className={secondaryBtn} onClick={() => open(row)}>{t('fieldWork.actions.edit')}</button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
