import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import PortalDatePicker from '@/Components/Calendar/PortalDatePicker';
import InputError from '@/Components/InputError';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Provider = { id: string; code: string; name_en: string; name_am?: string | null };
type Menu = {
    id: string; menu_date: string; title_en: string; title_am?: string | null;
    meal_type: string; price: number; subsidy_eligible: boolean;
    max_orders?: number | null; status: string;
    items?: { name_en: string; name_am?: string | null; item_type?: string | null; is_available: boolean }[];
};

const inputCls = 'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400/20';

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-sm font-medium text-gray-700 dark:text-slate-300">{label}</span>
            {children}
            <InputError message={error} className="mt-1" />
        </label>
    );
}

export default function MenuForm({ providers, selected_provider_id, menu }: {
    providers: Provider[];
    selected_provider_id: string | null;
    menu: Menu | null;
}) {
    const { t } = useLocale();
    const form = useForm({
        menu_date: menu?.menu_date ?? '',
        title_en: menu?.title_en ?? '',
        title_am: menu?.title_am ?? '',
        meal_type: menu?.meal_type ?? 'lunch',
        price: String(menu?.price ?? ''),
        subsidy_eligible: menu?.subsidy_eligible ?? true,
        max_orders: String(menu?.max_orders ?? ''),
        status: menu?.status ?? 'draft',
        items: menu?.items?.length ? menu.items : [{ name_en: '', name_am: '', item_type: '', is_available: true }],
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        if (menu) form.patch(route('provider.portal.menus.update', menu.id));
        else form.post(route('provider.portal.menus.store'));
    }

    function updateItem(index: number, key: 'name_en' | 'name_am' | 'item_type', value: string) {
        const items = [...form.data.items];
        items[index] = { ...items[index], [key]: value };
        form.setData('items', items);
    }

    const pageTitle = menu ? t('providerPortal.editMenu') : t('providerPortal.newMenu');

    return (
        <CafeteriaProviderPortalLayout
            title={pageTitle}
            header={
                <PageHeader
                    title={pageTitle}
                    backHref={menu ? route('provider.portal.menus.show', menu.id) : route('provider.portal.menus.index')}
                />
            }
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <Head title={pageTitle} />

            <div className="mx-auto max-w-2xl">
                <form onSubmit={submit} className="space-y-6">
                    {/* Basic details */}
                    <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-gray-100 px-5 py-4 dark:border-slate-800">
                            <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('providerPortal.menuDetails')}</p>
                        </div>
                        <div className="grid gap-4 p-5 md:grid-cols-2">
                            <Field label={t('providerPortal.date')} error={form.errors.menu_date}>
                                <PortalDatePicker value={form.data.menu_date} onChange={v => form.setData('menu_date', v)} className={inputCls} required />
                            </Field>
                            <Field label={t('providerPortal.mealType')} error={form.errors.meal_type}>
                                <select className={inputCls} value={form.data.meal_type} onChange={e => form.setData('meal_type', e.target.value)}>
                                    <option value="breakfast">Breakfast</option>
                                    <option value="lunch">Lunch</option>
                                    <option value="dinner">Dinner</option>
                                    <option value="snack">Snack</option>
                                </select>
                            </Field>
                            <Field label={t('providerPortal.titleEn')} error={form.errors.title_en}>
                                <input className={inputCls} value={form.data.title_en} onChange={e => form.setData('title_en', e.target.value)} required />
                            </Field>
                            <Field label={t('providerPortal.titleAm')} error={form.errors.title_am}>
                                <input className={inputCls} value={form.data.title_am} onChange={e => form.setData('title_am', e.target.value)} />
                            </Field>
                            <Field label={t('providerPortal.price')} error={form.errors.price}>
                                <input type="number" step="0.01" className={inputCls} value={form.data.price} onChange={e => form.setData('price', e.target.value)} required />
                            </Field>
                            <Field label={t('providerPortal.maxOrders')} error={form.errors.max_orders}>
                                <input type="number" className={inputCls} value={form.data.max_orders} onChange={e => form.setData('max_orders', e.target.value)} />
                            </Field>
                            <Field label={t('providerPortal.status')} error={form.errors.status}>
                                <select className={inputCls} value={form.data.status} onChange={e => form.setData('status', e.target.value)}>
                                    <option value="draft">Draft</option>
                                    <option value="published">Published</option>
                                    <option value="closed">Closed</option>
                                </select>
                            </Field>
                        </div>
                    </div>

                    {/* Menu items */}
                    <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-gray-100 px-5 py-4 dark:border-slate-800">
                            <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('providerPortal.items')}</p>
                        </div>
                        <div className="space-y-3 p-5">
                            {form.data.items.map((item, index) => (
                                <div key={index} className="grid gap-2 md:grid-cols-3">
                                    <input
                                        className={inputCls}
                                        placeholder={t('providerPortal.itemName')}
                                        value={item.name_en}
                                        onChange={e => updateItem(index, 'name_en', e.target.value)}
                                    />
                                    <input
                                        className={inputCls}
                                        placeholder={t('providerPortal.titleAm')}
                                        value={item.name_am ?? ''}
                                        onChange={e => updateItem(index, 'name_am', e.target.value)}
                                    />
                                    <input
                                        className={inputCls}
                                        placeholder={t('providerPortal.mealType')}
                                        value={item.item_type ?? ''}
                                        onChange={e => updateItem(index, 'item_type', e.target.value)}
                                    />
                                </div>
                            ))}
                            <button
                                type="button"
                                onClick={() => form.setData('items', [...form.data.items, { name_en: '', name_am: '', item_type: '', is_available: true }])}
                                className="text-sm font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400"
                            >
                                + {t('providerPortal.addItem')}
                            </button>
                        </div>
                    </div>

                    {/* Submit */}
                    <div className="flex justify-end gap-3">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-orange-500 px-5 py-2 text-sm font-semibold text-white hover:bg-orange-600 disabled:opacity-60"
                        >
                            {t('common.save')}
                        </button>
                    </div>
                </form>
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
