import { useLocale } from '@/hooks/useLocale';

type RequestType = 'new' | 'renewal' | 'replacement' | 'lost' | 'damaged' | 'correction';

type StyleEntry = {
    badge: string;
    icon: string;
};

const styleMap: Record<RequestType, StyleEntry> = {
    new: {
        badge: 'bg-blue-50 text-blue-800 ring-1 ring-blue-200 dark:bg-blue-900/20 dark:text-blue-300 dark:ring-blue-700/40',
        icon:  '✦',
    },
    renewal: {
        badge: 'bg-indigo-50 text-indigo-800 ring-1 ring-indigo-200 dark:bg-indigo-900/20 dark:text-indigo-300 dark:ring-indigo-700/40',
        icon:  '↻',
    },
    replacement: {
        badge: 'bg-orange-50 text-orange-800 ring-1 ring-orange-200 dark:bg-orange-900/20 dark:text-orange-300 dark:ring-orange-700/40',
        icon:  '⇄',
    },
    lost: {
        badge: 'bg-red-50 text-red-800 ring-1 ring-red-200 dark:bg-red-900/20 dark:text-red-300 dark:ring-red-700/40',
        icon:  '!',
    },
    damaged: {
        badge: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:ring-amber-700/40',
        icon:  '⚠',
    },
    correction: {
        badge: 'bg-purple-50 text-purple-800 ring-1 ring-purple-200 dark:bg-purple-900/20 dark:text-purple-300 dark:ring-purple-700/40',
        icon:  '✎',
    },
};

const fallbackStyle: StyleEntry = {
    badge: 'bg-gray-100 text-gray-600 ring-1 ring-gray-200',
    icon:  '·',
};

export default function CardRequestTypeBadge({ type }: { type: string }) {
    const { t } = useLocale();
    const label = t(`idCards.requestType_${type}`) || type.replace(/_/g, ' ');
    const style = styleMap[type as RequestType] ?? fallbackStyle;

    return (
        <span className={`inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ${style.badge}`}>
            <span className="text-[10px] font-bold shrink-0" aria-hidden>{style.icon}</span>
            {label}
        </span>
    );
}
