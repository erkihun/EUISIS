import { LAYOUT_LABEL_KEYS, ORGANOGRAM_LAYOUTS, type OrganogramLayout } from './shared';
import { useLocale } from '@/hooks/useLocale';

import { selectClassName } from '@/Components/ui';
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
            <span className="text-xs font-medium text-[color:var(--app-muted-foreground)]">
                {t('organizations.organogramType')}
            </span>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value as OrganogramLayout)}
                className={selectClassName}
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
