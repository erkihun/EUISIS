import { LAYOUT_LABEL_KEYS, ORGANOGRAM_LAYOUTS, type OrganogramLayout } from './shared';
import { useLocale } from '@/hooks/useLocale';

export default function OrganogramTypeSelector({
    value,
    onChange,
}: {
    value: OrganogramLayout;
    onChange: (layout: OrganogramLayout) => void;
}) {
    const { t } = useLocale();

    return (
        <label className="flex items-center gap-2 print:hidden">
            <span className="text-xs font-medium text-gray-600 dark:text-slate-400">
                {t('organizations.organogramType')}
            </span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value as OrganogramLayout)}
                className="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            >
                {ORGANOGRAM_LAYOUTS.map((layout) => (
                    <option key={layout} value={layout}>
                        {t(LAYOUT_LABEL_KEYS[layout])}
                    </option>
                ))}
            </select>
        </label>
    );
}
