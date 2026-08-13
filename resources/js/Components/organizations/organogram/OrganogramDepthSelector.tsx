import { DEPTH_LABEL_KEYS, ORGANOGRAM_DEPTHS, type OrganogramDepth } from './shared';
import { useLocale } from '@/hooks/useLocale';

export default function OrganogramDepthSelector({
    value,
    onChange,
}: {
    value: OrganogramDepth;
    onChange: (depth: OrganogramDepth) => void;
}) {
    const { t } = useLocale();

    return (
        <label className="flex items-center gap-2 print:hidden">
            <span className="text-xs font-medium text-gray-600 dark:text-slate-400">
                {t('organizations.displayDepth')}
            </span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value as OrganogramDepth)}
                className="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs text-gray-900 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
            >
                {ORGANOGRAM_DEPTHS.map((depth) => (
                    <option key={depth} value={depth}>
                        {t(DEPTH_LABEL_KEYS[depth])}
                    </option>
                ))}
            </select>
        </label>
    );
}
