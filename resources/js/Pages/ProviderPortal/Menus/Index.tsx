import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { FormEvent } from 'react';
import { useLocale } from '@/hooks/useLocale';

type Provider = { id: string; code: string; name_en: string; name_am?: string | null };
type Menu = { id: string; menu_date: string; title_en: string; title_am?: string | null; meal_type: string; price: number; status: string; orders_count?: number };

const STATUS_CLS: Record<string, string> = {
    draft:      'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400',
    published:  'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    closed:     'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
};

const inputCls = 'rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 focus:border-orange-400 focus:outline-none focus:ring-1 focus:ring-orange-400/20';

export default function MenusIndex({ providers, selected_provider_id, menus, filters }: {
    providers: Provider[];
    selected_provider_id: string | null;
    menus: Menu[];
    filters: Record<string, string>;
}) {
    const { t, locale } = useLocale();
    const title = (menu: Menu) => locale === 'am' && menu.title_am ? menu.title_am : menu.title_en;

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get(route('provider.portal.menus.index'), Object.fromEntries(new FormData(event.currentTarget)) as Record<string, string>, { preserveState: true });
    }

    return (
        <CafeteriaProviderPortalLayout
            title={t('providerPortal.menus')}
            header={
                <PageHeader
                    title={t('providerPortal.menus')}
                    actions={
                        <Link
                            href={route('provider.portal.menus.create')}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-orange-500 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-600"
                        >
                            + {t('providerPortal.newMenu')}
                        </Link>
                    }
                />
            }
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <Head title={t('providerPortal.menus')} />
            <div className="space-y-4">
                {/* Filters */}
                <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.date')}</label>
                        <input name="date" type="date" defaultValue={filters.date ?? ''} className={inputCls} />
                    </div>
                    <div>
                        <label className="mb-1 block text-xs font-medium text-gray-600 dark:text-slate-400">{t('providerPortal.status')}</label>
                        <select name="status" defaultValue={filters.status ?? ''} className={inputCls}>
                            <option value="">{t('common.all')}</option>
                            <option value="draft">{t('providerPortal.draft')}</option>
                            <option value="published">{t('providerPortal.published')}</option>
                            <option value="closed">{t('providerPortal.closed')}</option>
                        </select>
                    </div>
                    <button type="submit" className="rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600">
                        {t('common.filter')}
                    </button>
                </form>

                {/* Menu cards grid */}
                {menus.length === 0 ? (
                    <div className="rounded-card border border-gray-200 bg-white py-12 text-center dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-sm text-gray-400 dark:text-slate-500">{t('common.noRecords')}</p>
                    </div>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        {menus.map((menu) => (
                            <Link
                                key={menu.id}
                                href={route('provider.portal.menus.show', menu.id)}
                                className="group rounded-card border border-gray-200 bg-white p-4 transition hover:border-orange-300 hover:shadow-md dark:border-slate-800 dark:bg-slate-900 dark:hover:border-orange-700"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div className="min-w-0">
                                        <p className="text-xs text-gray-500 dark:text-slate-400"><LocalizedDateDisplay value={menu.menu_date} /> · {menu.meal_type}</p>
                                        <h2 className="mt-1 truncate font-semibold text-gray-900 dark:text-slate-100">{title(menu)}</h2>
                                    </div>
                                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium capitalize ${STATUS_CLS[menu.status] ?? 'bg-gray-100 text-gray-600'}`}>
                                        {menu.status}
                                    </span>
                                </div>
                                <div className="mt-3 flex items-center justify-between">
                                    <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">{menu.price.toFixed(2)}</p>
                                    {menu.orders_count !== undefined && (
                                        <p className="text-xs text-gray-400">{menu.orders_count} orders</p>
                                    )}
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
