import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { formatDateDisplay, formatDateTimeDisplay } from '@/lib/calendar/dateFormat';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    value: string | null | undefined;
    withTime?: boolean;
    className?: string;
    fallback?: string;
}

export default function LocalizedDateDisplay({ value, withTime = false, className, fallback = '—' }: Props) {
    const { locale } = useLocale();
    const system = useCalendarSystem();

    if (!value) return <span className={className}>{fallback}</span>;

    const display = withTime
        ? formatDateTimeDisplay(value, system, locale)
        : formatDateDisplay(value, system, locale);

    return <span className={className}>{display || fallback}</span>;
}
