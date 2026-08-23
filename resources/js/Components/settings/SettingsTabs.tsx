import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type Tab = {
    id: string;
    labelKey: string;
    disabled?: boolean;
    /**
     * When set, the tab navigates to its own page instead of switching the
     * in-page field group. Used for settings areas that are full CRUD modules
     * rather than a list of setting fields.
     */
    href?: string;
};

type Props = {
    tabs: Tab[];
    activeTab: string;
    onSelect: (tabId: string) => void;
};

export default function SettingsTabs({ tabs, activeTab, onSelect }: Props) {
    const { t } = useLocale();

    return (
        <nav
            aria-label={t('settings.title')}
            className="flex w-full overflow-x-auto border-b border-gray-200 dark:border-slate-800 bg-white dark:bg-slate-900 rounded-t-2xl"
        >
            {tabs.map((tab) => {
                const active = tab.id === activeTab;

                const className = [
                    'shrink-0 whitespace-nowrap px-5 py-3 text-sm font-medium border-b-2 transition-colors',
                    active
                        ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400'
                        : 'border-transparent text-gray-600 hover:text-gray-900 hover:border-gray-300 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:border-slate-600',
                    tab.disabled ? 'cursor-not-allowed opacity-50' : '',
                ].join(' ');

                if (tab.href) {
                    return (
                        <Link key={tab.id} href={tab.href} className={className}>
                            {t(tab.labelKey)}
                        </Link>
                    );
                }

                return (
                    <button
                        key={tab.id}
                        type="button"
                        onClick={() => onSelect(tab.id)}
                        disabled={tab.disabled}
                        className={[
                            'shrink-0 whitespace-nowrap px-5 py-3 text-sm font-medium border-b-2 transition-colors',
                            active
                                ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400'
                                : 'border-transparent text-gray-600 hover:text-gray-900 hover:border-gray-300 dark:text-slate-400 dark:hover:text-slate-200 dark:hover:border-slate-600',
                            tab.disabled ? 'cursor-not-allowed opacity-50' : '',
                        ].join(' ')}
                        aria-current={active ? 'page' : undefined}
                    >
                        {t(tab.labelKey)}
                    </button>
                );
            })}
        </nav>
    );
}
