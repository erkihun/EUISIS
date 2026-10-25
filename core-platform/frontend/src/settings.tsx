import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { api } from './api';
import { initTheme, type ThemePreference } from './lib/theme';

/** group → key → value, as GET /api/settings/public returns them. */
export type SettingValues = Record<string, Record<string, unknown>>;

type SettingsContext = {
    settings: SettingValues;
    /** "group.key"; the fallback when the setting is blank or missing. */
    getString: (name: string, fallback?: string) => string;
    getBoolean: (name: string, fallback?: boolean) => boolean;
    getNumber: (name: string, fallback?: number) => number;
    /** Reads the public settings again (after an administrator saves). */
    reload: () => Promise<void>;
};

const Context = createContext<SettingsContext | null>(null);

function read(settings: SettingValues, name: string): unknown {
    const [group, key] = name.split('.', 2);
    return settings[group]?.[key];
}

/**
 * The Core settings every page uses (branding, language, appearance, the
 * maintenance banner), like EUISIS's useSystemSettings. Loaded before the first
 * screen, so the sign-in page already shows the configured names and colours.
 */
export function SettingsProvider({ children }: { children: ReactNode }) {
    const [settings, setSettings] = useState<SettingValues | null>(null);

    const reload = useCallback(async () => {
        try {
            const response = await api<{ settings: SettingValues }>('/settings/public');
            // The theme before the first paint, as EUISIS app.tsx does with its settings.
            const appearance = response.settings.appearance ?? {};
            initTheme((appearance.default_theme as ThemePreference | undefined) ?? 'system', appearance.allow_user_theme_switching !== false);
            setSettings(response.settings);
        } catch {
            initTheme('system');
            // Without the API the shipped defaults (the fallbacks below) still render a usable page.
            setSettings((current) => current ?? {});
        }
    }, []);

    useEffect(() => {
        void reload();
    }, [reload]);

    const value = useMemo<SettingsContext | null>(() => {
        if (settings === null) return null;
        return {
            settings,
            getString: (name, fallback = '') => {
                const raw = read(settings, name);
                return typeof raw === 'string' && raw.trim() !== '' ? raw : fallback;
            },
            getBoolean: (name, fallback = false) => {
                const raw = read(settings, name);
                return typeof raw === 'boolean' ? raw : fallback;
            },
            getNumber: (name, fallback = 0) => {
                const raw = read(settings, name);
                return typeof raw === 'number' ? raw : fallback;
            },
            reload,
        };
    }, [settings, reload]);

    if (value === null) return null;

    return <Context.Provider value={value}>{children}</Context.Provider>;
}

export function useSettings(): SettingsContext {
    const context = useContext(Context);
    if (context === null) throw new Error('useSettings outside SettingsProvider');
    return context;
}

/**
 * EUISIS's useBranding over the Core settings: the brand per language, the
 * official names and the institution names the sign-in page and sidebar show.
 */
export function useBranding(locale: 'en' | 'am') {
    const { getString } = useSettings();
    const brandEn = getString('general.application_name', 'Agelgay');
    const brandAm = getString('general.application_name_am', 'አገልጋይ');
    const officialEn = getString('general.official_name_en', 'Addis Ababa Integrated Employee Management & Service System');
    const officialAm = getString('general.official_name_am', officialEn);
    const organization = getString('general.organization_name', 'Addis Ababa City Administration');

    return {
        brandName: locale === 'am' ? brandAm : brandEn,
        shortName: getString('general.application_short_name', 'AA-IEMSS'),
        officialName: locale === 'am' ? officialAm : officialEn,
        orgName: locale === 'am'
            ? getString('id_cards.city_name_am', 'አዲስ አበባ ከተማ አስተዳደር')
            : getString('id_cards.city_name_en', organization),
        bureauName: locale === 'am'
            ? getString('id_cards.bureau_name_am', 'የሲቪል ሰርቪስና ሰው ሀብት ልማት ቢሮ')
            : getString('id_cards.bureau_name_en', 'Public Service & HRD Bureau'),
    };
}
