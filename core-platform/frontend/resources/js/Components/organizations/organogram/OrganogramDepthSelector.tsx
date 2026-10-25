import { DEPTH_LABEL_KEYS, ORGANOGRAM_DEPTHS, type OrganogramDepth } from './shared';
import { useLocale } from '@/hooks/useLocale';

import { selectClassName } from '@/Components/ui';
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
            <span className="text-xs font-medium text-(--app-muted-foreground)">
                {t('organizations.displayDepth')}
            </span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value as OrganogramDepth)}
                className={selectClassName}
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
