import { useLocale } from '@/hooks/useLocale';

type CardStatus =
    | 'pending_print' | 'printed' | 'issued' | 'active'
    | 'lost' | 'damaged' | 'suspended' | 'revoked' | 'expired' | 'replaced';

type StyleEntry = {
    badge: string;
    dot: string;
};

const styleMap: Record<CardStatus, StyleEntry> = {
    pending_print: {
        badge: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40',
        dot:   'bg-amber-400',
    },
    printed: {
        badge: 'bg-blue-50 text-blue-800 ring-1 ring-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40',
        dot:   'bg-blue-400',
    },
    issued: {
        badge: 'bg-indigo-50 text-indigo-800 ring-1 ring-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:ring-indigo-700/40',
        dot:   'bg-indigo-400',
    },
    active: {
        badge: 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:ring-emerald-700/40',
        dot:   'bg-emerald-400',
    },
    lost: {
        badge: 'bg-red-50 text-red-800 ring-1 ring-red-200 dark:bg-red-900/20 dark:text-red-300 dark:ring-red-700/40',
        dot:   'bg-red-400',
    },
    damaged: {
        badge: 'bg-orange-50 text-orange-800 ring-1 ring-orange-200 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-700/40',
        dot:   'bg-orange-400',
    },
    suspended: {
        badge: 'bg-yellow-50 text-yellow-800 ring-1 ring-yellow-200 dark:bg-yellow-900/20 dark:text-yellow-300 dark:ring-yellow-700/40',
        dot:   'bg-yellow-400',
    },
    revoked: {
        badge: 'bg-red-100 text-red-900 ring-1 ring-red-300 dark:bg-red-900/30 dark:text-red-200 dark:ring-red-700/50',
        dot:   'bg-red-600',
    },
    expired: {
        badge: 'bg-gray-100 text-gray-600 ring-1 ring-gray-200 dark:bg-slate-800 dark:text-slate-400 dark:ring-slate-700',
        dot:   'bg-gray-400',
    },
    replaced: {
        badge: 'bg-gray-50 text-gray-500 ring-1 ring-gray-200 dark:bg-slate-800 dark:text-slate-500 dark:ring-slate-700',
        dot:   'bg-gray-300',
    },
};

const fallbackStyle: StyleEntry = {
    badge: 'bg-gray-100 text-gray-600 ring-1 ring-gray-200',
    dot:   'bg-gray-400',
};

export default function CardStatusBadge({ status }: { status: string }) {
    const { t } = useLocale();
    const label = t(`idCards.status_${status}`) || status.replace(/_/g, ' ');
    const style = styleMap[status as CardStatus] ?? fallbackStyle;

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${style.badge}`}>
            <span className={`h-1.5 w-1.5 rounded-full shrink-0 ${style.dot}`} aria-hidden />
            {label}
        </span>
    );
}
