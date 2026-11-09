/**
 * PortalDatePicker — like LocalizedDatePicker but always uses locale-based
 * calendar selection, ignoring the system-wide calendar_mode setting.
 *
 * The admin may set calendar_mode = 'gregorian_only' for the main UI, but the
 * cafeteria provider portal should always show Ethiopian calendar for `am` users.
 *
 * Value in/out: Gregorian ISO "YYYY-MM-DD" (same contract as LocalizedDatePicker).
 */
import { useLocale } from '@/hooks/useLocale';
import EthiopianDatePicker from './EthiopianDatePicker';
import GregorianDatePicker from './GregorianDatePicker';

interface Props {
    value: string;
    onChange: (gregorianIso: string) => void;
    min?: string;
    max?: string;
    disabled?: boolean;
    required?: boolean;
    className?: string;
    placeholder?: string;
    id?: string;
    name?: string;
}

export default function PortalDatePicker(props: Props) {
    const { locale } = useLocale();
    // Always use locale to decide — never honour a system-wide gregorian_only override
    return locale === 'am'
        ? <EthiopianDatePicker {...props} />
        : <GregorianDatePicker {...props} />;
}
