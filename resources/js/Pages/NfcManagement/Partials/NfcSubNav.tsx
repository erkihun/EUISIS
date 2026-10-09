import { Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

/** Permission flags the server sends with every NFC screen. */
export type NfcCapabilities = {
    viewCredentials: boolean;
    viewLogs: boolean;
    viewTerminals: boolean;
    createTerminals: boolean;
    updateTerminals: boolean;
    deleteTerminals: boolean;
};

type Tab = 'dashboard' | 'credentials' | 'terminals' | 'logs';

/**
 * Tabs across the NFC module.
 *
 * Each tab is dropped when the viewer lacks the permission behind it, matching
 * how the sidebar hides the same destinations — a user never sees a link that
 * would answer 403.
 */
export default function NfcSubNav({ can, current }: { can: NfcCapabilities; current: Tab }) {
    const { t } = useLocale();

    const tabs: { key: Tab; label: string; href: string; visible: boolean }[] = [
        {
            key: 'dashboard',
            label: t('nfc.dashboard'),
            href: route('nfc-management.dashboard'),
            visible: can.viewCredentials,
        },
        {
            key: 'credentials',
            label: t('nfc.credentials'),
            href: route('nfc-management.credentials.index'),
            visible: can.viewCredentials,
        },
        {
            key: 'terminals',
            label: t('nfc.terminals'),
            href: route('nfc-management.terminals.index'),
            visible: can.viewTerminals,
        },
        {
            key: 'logs',
            label: t('nfc.verificationLogs'),
            href: route('nfc-management.logs.index'),
            visible: can.viewLogs,
        },
    ];

    const visible = tabs.filter((tab) => tab.visible);

    // A single remaining tab is just a label for the page you are already on.
    if (visible.length < 2) return null;

    return (
        <nav className="my-4 flex flex-wrap gap-1 border-b border-gray-200 dark:border-slate-800">
            {visible.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    aria-current={tab.key === current ? 'page' : undefined}
                    className={`-mb-px rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium transition ${
                        tab.key === current
                            ? 'border-blue-600 text-blue-700 dark:border-blue-400 dark:text-[color:var(--color-primary)]'
                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-800 dark:text-slate-400 dark:hover:text-slate-200'
                    }`}
                >
                    {tab.label}
                </Link>
            ))}
        </nav>
    );
}

/** Shared helper: pick the bilingual name for the active locale. */
export function useLocalizedName() {
    const { locale } = useLocale();

    return (record?: { name_en?: string | null; name_am?: string | null } | null): string => {
        if (!record) return '';

        return (locale === 'am' ? (record.name_am ?? record.name_en) : record.name_en) ?? '';
    };
}
