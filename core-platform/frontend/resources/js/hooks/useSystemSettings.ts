import { usePage } from '@inertiajs/react';
import { createContext, useContext } from 'react';
import { useIdCardTemplate } from '@/Components/IdCards/IdCardTemplateContext';
import type { PageProps } from '@/types';
import {
    getBooleanSetting,
    getNumberSetting,
    getSetting,
    getStringArraySetting,
    getStringSetting,
    type PublicSettings,
} from '@/lib/settings';

export const SystemSettingsPreviewContext = createContext<PublicSettings | null>(null);

export function useSystemSettings() {
    const page = usePage<PageProps<{ settings?: PublicSettings }>>();
    const overrides = useContext(SystemSettingsPreviewContext);
    const template = useIdCardTemplate();
    const settings = { ...page.props.settings, ...overrides };
    // PNG artwork uses fixed overlay colors; stored legacy colors remain available for fallback cards.
    if (template?.front_background_url) {
        Object.assign(settings, {
            'id_cards.front_bg_from': '#1D4ED8', 'id_cards.front_bg_to': '#1E3A8A',
            'id_cards.front_text_primary': '#FFFFFF', 'id_cards.front_text_secondary': '#BFDBFE',
        });
    }
    if (template?.back_background_url) {
        Object.assign(settings, {
            'id_cards.back_bg_from': '#1E293B', 'id_cards.back_bg_to': '#0F172A', 'id_cards.back_text_color': '#94A3B8',
        });
    }

    return {
        settings,
        get: <T,>(key: string, fallback: T): T => getSetting(settings, key, fallback),
        getString: (key: string, fallback = ''): string => getStringSetting(settings, key, fallback),
        getBoolean: (key: string, fallback = false): boolean => getBooleanSetting(settings, key, fallback),
        getNumber: (key: string, fallback = 0): number => getNumberSetting(settings, key, fallback),
        getStringArray: (key: string, fallback: string[] = []): string[] => getStringArraySetting(settings, key, fallback),
    };
}
