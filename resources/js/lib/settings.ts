export type PublicSettings = Record<string, unknown>;

export type SettingsField = {
    key: string;
    group: string;
    type: string;
    value: unknown;
    is_encrypted: boolean;
    is_public: boolean;
    configured: boolean;
    options: string[] | null;
    label_en: string | null;
    label_am: string | null;
    description_en?: string | null;
    description_am?: string | null;
    sort_order: number;
    default: unknown;
    validation_rules?: string[] | null;
    is_required?: boolean;
    asset_url?: string | null;
};

export type SettingsGroupPayload = {
    fields: SettingsField[];
    can_manage: boolean;
};

export function getSetting<T>(
    settings: PublicSettings | undefined,
    key: string,
    fallback: T,
): T {
    const value = settings?.[key];

    return (value ?? fallback) as T;
}

export function getStringSetting(
    settings: PublicSettings | undefined,
    key: string,
    fallback = '',
): string {
    const value = settings?.[key];

    return typeof value === 'string' ? value : fallback;
}

export function getBooleanSetting(
    settings: PublicSettings | undefined,
    key: string,
    fallback = false,
): boolean {
    const value = settings?.[key];

    return typeof value === 'boolean' ? value : fallback;
}

export function getNumberSetting(
    settings: PublicSettings | undefined,
    key: string,
    fallback = 0,
): number {
    const value = settings?.[key];

    return typeof value === 'number' ? value : fallback;
}

export function getStringArraySetting(
    settings: PublicSettings | undefined,
    key: string,
    fallback: string[] = [],
): string[] {
    const value = settings?.[key];

    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : fallback;
}
