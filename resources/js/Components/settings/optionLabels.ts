type Translate = (key: string) => string;

const SAMPLE_DATE = new Date(2026, 8, 28, 14, 30);

/** PHP date tokens used by the date-format options, rendered for a fixed sample day. */
function sampleDate(format: string): string {
    const pad = (value: number) => String(value).padStart(2, '0');
    const parts: Record<string, string> = {
        Y: String(SAMPLE_DATE.getFullYear()),
        m: pad(SAMPLE_DATE.getMonth() + 1),
        d: pad(SAMPLE_DATE.getDate()),
    };
    return format.replace(/[Ymd]/g, (token) => parts[token] ?? token);
}

function readable(value: string): string {
    const text = value.replace(/[_.]+/g, ' ').trim();
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * The label shown for a select option. Values that are formats or numbers are
 * shown as an example; the rest come from settings.options.<field>.<value>,
 * falling back to a readable form of the stored value.
 */
export function optionLabel(fieldKey: string, option: string, t: Translate, locale: string): string {
    if (fieldKey === 'date_format') return `${sampleDate(option)}  (${option})`;
    if (fieldKey === 'number_format') return option;
    if (fieldKey === 'qr_size' && /^\d+$/.test(option)) return `${option} px`;
    if (fieldKey === 'allowed_file_types') return option.toUpperCase();
    if (fieldKey === 'first_day_of_week' && /^\d$/.test(option)) {
        // 2026-09-27 is a Sunday, so adding the day index gives that weekday.
        return new Intl.DateTimeFormat(locale === 'am' ? 'am-ET' : 'en', { weekday: 'long' }).format(new Date(2026, 8, 27 + Number(option)));
    }
    if (['default_locale', 'fallback_locale', 'supported_locales'].includes(fieldKey)) {
        if (option === 'en') return t('common.english');
        if (option === 'am') return t('common.amharic');
    }

    const key = `settings.options.${fieldKey}.${option.replace(/\./g, '_')}`;
    const label = t(key);
    return label !== key ? label : readable(option);
}
