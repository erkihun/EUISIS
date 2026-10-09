import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

type ParentOption = { id: string; isic_code: string; name_en: string | null; level: string };

export default function IsicActivitiesCreate({
    levels,
    parents,
}: {
    levels: string[];
    parents: ParentOption[];
}) {
    const { t } = useLocale();
    const form = useForm({
        isic_code: '',
        level: 'section',
        section_code: '',
        division_code: '',
        group_code: '',
        class_code: '',
        parent_id: '',
        name_en: '',
        name_am: '',
        description_en: '',
        description_am: '',
        sort_order: '0',
        is_active: true,
    });

    const inputCls =
        'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('isic-activities.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('isicActivities.createIsicActivity')} />}>
            <Head title={t('isicActivities.createIsicActivity')} />
            <form
                className="space-y-6 rounded-panel border border-gray-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900"
                onSubmit={submit}
            >
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="isic_code" value={t('isicActivities.isicCode')} />
                        <input
                            id="isic_code"
                            className={inputCls}
                            value={form.data.isic_code}
                            onChange={(e) => form.setData('isic_code', e.target.value.toUpperCase())}
                        />
                        {form.errors.isic_code && <p className="text-xs text-red-600">{form.errors.isic_code}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="level" value={t('isicActivities.activityLevel')} />
                        <select
                            id="level"
                            className={inputCls}
                            value={form.data.level}
                            onChange={(e) => form.setData('level', e.target.value)}
                        >
                            {levels.map((l) => (
                                <option key={l} value={l}>
                                    {t(`isicActivities.${l}` as any) || l}
                                </option>
                            ))}
                        </select>
                        {form.errors.level && <p className="text-xs text-red-600">{form.errors.level}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="section_code" value={t('isicActivities.sectionCode')} />
                        <input
                            id="section_code"
                            maxLength={1}
                            className={inputCls}
                            value={form.data.section_code}
                            onChange={(e) => form.setData('section_code', e.target.value.toUpperCase())}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="division_code" value={t('isicActivities.divisionCode')} />
                        <input
                            id="division_code"
                            maxLength={2}
                            className={inputCls}
                            value={form.data.division_code}
                            onChange={(e) => form.setData('division_code', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="group_code" value={t('isicActivities.groupCode')} />
                        <input
                            id="group_code"
                            maxLength={3}
                            className={inputCls}
                            value={form.data.group_code}
                            onChange={(e) => form.setData('group_code', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="class_code" value={t('isicActivities.classCode')} />
                        <input
                            id="class_code"
                            maxLength={4}
                            className={inputCls}
                            value={form.data.class_code}
                            onChange={(e) => form.setData('class_code', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="parent_id" value={t('isicActivities.parentActivity')} />
                        <select
                            id="parent_id"
                            className={inputCls}
                            value={form.data.parent_id}
                            onChange={(e) => form.setData('parent_id', e.target.value)}
                        >
                            <option value="">{t('isicActivities.noParent')}</option>
                            {parents.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.isic_code} · {p.name_en ?? ''}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="name_en" value={t('isicActivities.nameEn')} />
                        <input
                            id="name_en"
                            className={inputCls}
                            value={form.data.name_en}
                            onChange={(e) => form.setData('name_en', e.target.value)}
                        />
                        {form.errors.name_en && <p className="text-xs text-red-600">{form.errors.name_en}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="name_am" value={t('isicActivities.nameAm')} />
                        <input
                            id="name_am"
                            className={inputCls}
                            value={form.data.name_am}
                            onChange={(e) => form.setData('name_am', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <InputLabel htmlFor="sort_order" value={t('isicActivities.sortOrder')} />
                        <input
                            id="sort_order"
                            type="number"
                            min="0"
                            className={inputCls}
                            value={form.data.sort_order}
                            onChange={(e) => form.setData('sort_order', e.target.value)}
                        />
                    </div>
                </div>
                <div className="space-y-1.5">
                    <InputLabel htmlFor="description_en" value={t('isicActivities.descriptionEn')} />
                    <textarea
                        id="description_en"
                        className={`${inputCls} min-h-28`}
                        value={form.data.description_en}
                        onChange={(e) => form.setData('description_en', e.target.value)}
                    />
                </div>
                <div className="space-y-1.5">
                    <InputLabel htmlFor="description_am" value={t('isicActivities.descriptionAm')} />
                    <textarea
                        id="description_am"
                        className={`${inputCls} min-h-28`}
                        value={form.data.description_am}
                        onChange={(e) => form.setData('description_am', e.target.value)}
                    />
                </div>
                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-slate-300">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(e) => form.setData('is_active', e.target.checked)}
                    />
                    {t('isicActivities.isActive')}
                </label>
                <div className="flex gap-3">
                    <button
                        className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                        type="submit"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </button>
                    <Link
                        href={route('isic-activities.index')}
                        className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 dark:border-slate-700 dark:text-slate-200"
                    >
                        {t('common.cancel')}
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
