import FieldRow from '@/Components/settings/FieldRow';
import { useLocale } from '@/hooks/useLocale';
import { SearchableSelect } from '@euisis/ui';
import { useId, useMemo } from 'react';

type Props = {
    label: string;
    description?: string | null;
    value: string;
    error?: string;
    disabled?: boolean;
    onChange: (value: string) => void;
};

/** Used when the browser cannot list its time zones. */
const FALLBACK_ZONES = [
    'Africa/Addis_Ababa', 'Africa/Cairo', 'Africa/Djibouti', 'Africa/Johannesburg', 'Africa/Kampala', 'Africa/Khartoum', 'Africa/Lagos', 'Africa/Mogadishu', 'Africa/Nairobi',
    'Asia/Dubai', 'Asia/Riyadh', 'Asia/Kolkata', 'Asia/Shanghai', 'Asia/Tokyo', 'Europe/Berlin', 'Europe/Istanbul', 'Europe/London', 'Europe/Paris',
    'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Australia/Sydney', 'UTC',
];

function zoneList(): string[] {
    try {
        const zones = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone');
        if (zones && zones.length > 0) return zones.includes('UTC') ? zones : [...zones, 'UTC'];
    } catch {
        // Older browsers: use the short list.
    }
    return FALLBACK_ZONES;
}

/** Current UTC offset of a zone, such as "UTC+03:00". */
function offsetLabel(zone: string): string {
    try {
        const part = new Intl.DateTimeFormat('en', { timeZone: zone, timeZoneName: 'longOffset' }).formatToParts(new Date()).find((entry) => entry.type === 'timeZoneName');
        return part?.value === 'GMT' ? 'UTC+00:00' : (part?.value.replace('GMT', 'UTC') ?? '');
    } catch {
        return '';
    }
}

export default function TimezoneSettingField({ label, description, value, error, disabled = false, onChange }: Props) {
    const { t } = useLocale();
    const id = useId();
    const options = useMemo(() => {
        const zones = zoneList();
        return (value && !zones.includes(value) ? [value, ...zones] : zones)
            .map((zone) => ({ value: zone, label: `${zone.replace(/_/g, ' ')} (${offsetLabel(zone)})` }));
    }, [value]);

    return (
        <FieldRow labelId={`${id}-label`} label={label} description={description} error={error}>
            <SearchableSelect label={label} immediate value={value} options={options} disabled={disabled} clearable={false}
                placeholder={t('settings.timezonePlaceholder')} searchPlaceholder={t('settings.timezoneSearch')} emptyText={t('settings.timezoneEmpty')}
                onChange={(next) => { if (next) onChange(next); }} />
        </FieldRow>
    );
}
