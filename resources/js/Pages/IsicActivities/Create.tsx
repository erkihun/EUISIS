import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

import { buttonClassName, cx, inputClassName, FormLabel } from '@/Components/ui';
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

    const inputCls = cx(inputClassName, 'w-full');

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('isic-activities.store'));
    }

    return (
        <AuthenticatedLayout header={<PageHeader title={t('isicActivities.createIsicActivity')} />}>
            <Head title={t('isicActivities.createIsicActivity')} />
            <form
                className="space-y-6 rounded-panel border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-6"
                onSubmit={submit}
            >
                <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="isic_code">{t('isicActivities.isicCode')}</FormLabel>
                        <input
                            id="isic_code"
                            className={inputCls}
                            value={form.data.isic_code}
                            onChange={(e) => form.setData('isic_code', e.target.value.toUpperCase())}
                        />
                        {form.errors.isic_code && <p className="text-xs text-red-600">{form.errors.isic_code}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="level">{t('isicActivities.activityLevel')}</FormLabel>
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
                        <FormLabel htmlFor="section_code">{t('isicActivities.sectionCode')}</FormLabel>
                        <input
                            id="section_code"
                            maxLength={1}
                            className={inputCls}
                            value={form.data.section_code}
                            onChange={(e) => form.setData('section_code', e.target.value.toUpperCase())}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="division_code">{t('isicActivities.divisionCode')}</FormLabel>
                        <input
                            id="division_code"
                            maxLength={2}
                            className={inputCls}
                            value={form.data.division_code}
                            onChange={(e) => form.setData('division_code', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="group_code">{t('isicActivities.groupCode')}</FormLabel>
                        <input
                            id="group_code"
                            maxLength={3}
                            className={inputCls}
                            value={form.data.group_code}
                            onChange={(e) => form.setData('group_code', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="class_code">{t('isicActivities.classCode')}</FormLabel>
                        <input
                            id="class_code"
                            maxLength={4}
                            className={inputCls}
                            value={form.data.class_code}
                            onChange={(e) => form.setData('class_code', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="parent_id">{t('isicActivities.parentActivity')}</FormLabel>
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
                        <FormLabel htmlFor="name_en">{t('isicActivities.nameEn')}</FormLabel>
                        <input
                            id="name_en"
                            className={inputCls}
                            value={form.data.name_en}
                            onChange={(e) => form.setData('name_en', e.target.value)}
                        />
                        {form.errors.name_en && <p className="text-xs text-red-600">{form.errors.name_en}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="name_am">{t('isicActivities.nameAm')}</FormLabel>
                        <input
                            id="name_am"
                            className={inputCls}
                            value={form.data.name_am}
                            onChange={(e) => form.setData('name_am', e.target.value)}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <FormLabel htmlFor="sort_order">{t('isicActivities.sortOrder')}</FormLabel>
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
                    <FormLabel htmlFor="description_en">{t('isicActivities.descriptionEn')}</FormLabel>
                    <textarea
                        id="description_en"
                        className={`${inputCls} min-h-28`}
                        value={form.data.description_en}
                        onChange={(e) => form.setData('description_en', e.target.value)}
                    />
                </div>
                <div className="space-y-1.5">
                    <FormLabel htmlFor="description_am">{t('isicActivities.descriptionAm')}</FormLabel>
                    <textarea
                        id="description_am"
                        className={`${inputCls} min-h-28`}
                        value={form.data.description_am}
                        onChange={(e) => form.setData('description_am', e.target.value)}
                    />
                </div>
                <label className="flex items-center gap-2 text-sm text-[color:var(--app-foreground)]">
                    <input
                        type="checkbox"
                        checked={form.data.is_active}
                        onChange={(e) => form.setData('is_active', e.target.checked)}
                    />
                    {t('isicActivities.isActive')}
                </label>
                <div className="flex gap-3">
                    <button
                        className={buttonClassName({ variant: 'primary' })}
                        type="submit"
                        disabled={form.processing}
                    >
                        {t('common.save')}
                    </button>
                    <Link
                        href={route('isic-activities.index')}
                        className="rounded-lg border border-[color:var(--app-border-strong)] px-4 py-2 text-sm font-medium text-[color:var(--app-foreground)]"
                    >
                        {t('common.cancel')}
                    </Link>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
