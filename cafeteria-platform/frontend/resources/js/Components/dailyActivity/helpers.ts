import type { Named } from './types';
import { buttonClassName, cx, inputClassName } from '@/Components/ui';

/** Replace `{name}` placeholders; the shared t() has no interpolation. */
export function fill(template: string, params: Record<string, string | number>): string {
    return Object.entries(params).reduce(
        (text, [key, value]) => text.split(`{${key}}`).join(String(value)),
        template,
    );
}

export function named(value: Named | undefined, locale: string): string {
    if (!value) return '';
    return locale === 'am' && value.name_am ? value.name_am : value.name_en;
}

// The shared kit's controls and buttons (docs/ui-components.md).
export const inputCls = cx(inputClassName, 'w-full');

export const compactInputCls = inputClassName;

export const primaryBtn = buttonClassName({ variant: 'primary' });

export const secondaryBtn = buttonClassName({ variant: 'outline' });

export const dangerBtn = buttonClassName({ variant: 'destructiveOutline' });

export const panelCls = 'rounded-panel border border-(--app-border) bg-(--app-surface)';

export const labelCls = 'mb-1 block text-xs font-medium text-(--app-muted-foreground)';

export function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
