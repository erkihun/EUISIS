import CafeteriaProviderPortalLayout from '@/Layouts/CafeteriaProviderPortalLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link, router } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Provider = { id: string; code: string; name_en: string; name_am?: string | null };
type Menu = {
    id: string; menu_date: string; title_en: string; title_am?: string | null;
    meal_type: string; price: number; status: string;
    items?: { id: string; name_en: string; name_am?: string | null }[];
};

const STATUS_CLS: Record<string, string> = {
    draft:      'bg-gray-100 text-gray-600 dark:bg-slate-800 dark:text-slate-400',
    published:  'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
    closed:     'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
};

function DetailRow({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="grid grid-cols-3 border-b border-gray-100 py-3 text-sm last:border-0 dark:border-slate-800">
            <span className="text-gray-500 dark:text-slate-400">{label}</span>
            <span className="col-span-2 font-medium text-gray-900 dark:text-slate-100">{children}</span>
        </div>
    );
}

export default function MenuShow({ providers, selected_provider_id, menu }: {
    providers: Provider[];
    selected_provider_id: string | null;
    menu: Menu;
}) {
    const { t, locale } = useLocale();
    const title = locale === 'am' && menu.title_am ? menu.title_am : menu.title_en;

    return (
        <CafeteriaProviderPortalLayout
            title={title}
            header={
                <PageHeader
                    title={title}
                    backHref={route('provider.portal.menus.index')}
                    actions={
                        <div className="flex items-center gap-2">
                            <Link
                                href={route('provider.portal.menus.edit', menu.id)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                            >
                                {t('common.edit')}
                            </Link>
                            <button
                                onClick={() => router.post(route('provider.portal.menus.publish', menu.id))}
                                className="inline-flex items-center gap-1.5 rounded-lg bg-orange-500 px-3 py-2 text-sm font-semibold text-white hover:bg-orange-600"
                            >
                                {t('providerPortal.publish')}
                            </button>
                        </div>
                    }
                />
            }
            providers={providers}
            selectedProviderId={selected_provider_id}
        >
            <Head title={title} />

            <div className="mx-auto max-w-2xl space-y-4">
                {/* Details */}
                <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="border-b border-gray-100 px-5 py-4 dark:border-slate-800">
                        <p className="text-xs font-semibold text-gray-500 dark:text-slate-400">{t('providerPortal.menus')}</p>
                        <p className="mt-1 font-semibold text-gray-900 dark:text-slate-100">{title}</p>
                    </div>
                    <div className="px-5">
                        <DetailRow label={t('providerPortal.date')}><LocalizedDateDisplay value={menu.menu_date} /></DetailRow>
                        <DetailRow label={t('providerPortal.mealType')}>{menu.meal_type}</DetailRow>
                        <DetailRow label={t('providerPortal.price')}>{menu.price.toFixed(2)}</DetailRow>
                        <DetailRow label={t('providerPortal.status')}>
                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium capitalize ${STATUS_CLS[menu.status] ?? 'bg-gray-100 text-gray-600'}`}>
                                {menu.status}
                            </span>
                        </DetailRow>
                    </div>
                </div>

                {/* Menu items */}
                {menu.items && menu.items.length > 0 && (
                    <div className="overflow-hidden rounded-card border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-gray-100 px-5 py-4 dark:border-slate-800">
                            <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t('providerPortal.items')}</p>
                        </div>
                        <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                            {menu.items.map(item => (
                                <li key={item.id} className="px-5 py-3 text-sm text-gray-900 dark:text-slate-100">
                                    {(locale === 'am' && item.name_am) ? item.name_am : item.name_en}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </CafeteriaProviderPortalLayout>
    );
}
