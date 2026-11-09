import { useState } from 'react';
import { useLocale } from '@/hooks/useLocale';

type ParentMode = 'any' | 'root' | 'selected';

function modeOf(value: string[] | null): ParentMode {
    if (value === null) return 'any';

    return value.length === 0 ? 'root' : 'selected';
}

/**
 * The three states of a type's allowed parents: null means no restriction, an
 * empty list means a root type with no parent, and a list names the parents.
 * Checkboxes alone cannot tell the first two apart.
 */
export default function ParentAllowedTypesInput({
    value,
    onChange,
    types,
}: {
    value: string[] | null;
    onChange: (value: string[] | null) => void;
    types: { code: string; name_en: string; name_am?: string | null }[];
}) {
    const { locale, t } = useLocale();
    const [mode, setMode] = useState<ParentMode>(modeOf(value));
    const selected = value ?? [];

    function choose(next: ParentMode) {
        setMode(next);
        if (next === 'any') onChange(null);
        if (next === 'root') onChange([]);
        if (next === 'selected') onChange(selected);
    }

    function toggle(code: string) {
        onChange(selected.includes(code) ? selected.filter((c) => c !== code) : [...selected, code]);
    }

    const modes: { key: ParentMode; label: string }[] = [
        { key: 'any', label: t('organizationTypes.noParentRestriction') },
        { key: 'root', label: t('organizationTypes.rootOnly') },
        { key: 'selected', label: t('organizationTypes.selectedParentsOnly') },
    ];

    return (
        <div className="mt-1 space-y-2">
            <div className="flex flex-wrap gap-x-4 gap-y-1">
                {modes.map(({ key, label }) => (
                    <label key={key} className="flex cursor-pointer items-center gap-2 text-sm text-(--app-foreground)">
                        <input
                            type="radio"
                            name="parent_allowed_types_mode"
                            className="h-3.5 w-3.5 border-(--app-border-strong) text-primary focus:ring-primary"
                            checked={mode === key}
                            onChange={() => choose(key)}
                        />
                        {label}
                    </label>
                ))}
            </div>
            {mode === 'selected' && (
                <div className="grid max-h-56 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-(--app-border) p-2 md:grid-cols-2 xl:grid-cols-3">
                    {types.map((type) => (
                        <label
                            key={type.code}
                            className="flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1 hover:bg-(--app-surface-muted)"
                        >
                            <input
                                type="checkbox"
                                className="h-3.5 w-3.5 rounded-sm border-(--app-border-strong) text-primary focus:ring-primary"
                                checked={selected.includes(type.code)}
                                onChange={() => toggle(type.code)}
                            />
                            <span className="font-mono text-xs text-(--app-muted-foreground)">{type.code}</span>
                            <span className="text-xs text-(--app-foreground)">
                                {(locale === 'am' ? type.name_am : type.name_en) ?? type.name_en}
                            </span>
                        </label>
                    ))}
                </div>
            )}
        </div>
    );
}
