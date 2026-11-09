/**
 * LocalizedDatePicker — drop-in replacement for <input type="date">.
 *
 * - Value in: Gregorian ISO string "YYYY-MM-DD" (or "")
 * - Value out: Gregorian ISO string via onChange
 * - Display: Ethiopian calendar for `am` locale, Gregorian for `en`
 *
 * The form always submits Gregorian ISO regardless of display locale.
 */

import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
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

export default function LocalizedDatePicker(props: Props) {
    const system = useCalendarSystem();

    if (system === 'ethiopian') {
        return <EthiopianDatePicker {...props} />;
    }

    return <GregorianDatePicker {...props} />;
}
