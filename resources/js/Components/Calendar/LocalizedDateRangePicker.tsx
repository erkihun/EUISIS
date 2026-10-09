/**
 * Date range picker: start + end date, both in Gregorian ISO format.
 * Uses LocalizedDatePicker for each field.
 */

import LocalizedDatePicker from './LocalizedDatePicker';
import { useLocale } from '@/hooks/useLocale';

interface Props {
    startValue: string;
    endValue: string;
    onStartChange: (gregorianIso: string) => void;
    onEndChange: (gregorianIso: string) => void;
    startMin?: string;
    startMax?: string;
    endMin?: string;
    endMax?: string;
    disabled?: boolean;
    className?: string;
    startId?: string;
    endId?: string;
    startName?: string;
    endName?: string;
}

export default function LocalizedDateRangePicker({
    startValue,
    endValue,
    onStartChange,
    onEndChange,
    startMin,
    startMax,
    endMin,
    endMax,
    disabled = false,
    className = '',
    startId,
    endId,
    startName,
    endName,
}: Props) {
    const { t } = useLocale();

    const computedEndMin = endMin ?? startValue ?? undefined;
    const computedStartMax = startMax ?? endValue ?? undefined;

    return (
        <div className={`flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3 ${className}`}>
            <LocalizedDatePicker
                id={startId}
                name={startName}
                value={startValue}
                onChange={onStartChange}
                min={startMin}
                max={computedStartMax}
                disabled={disabled}
                placeholder={t('calendar.startDate')}
                className="flex-1"
            />
            <span className="text-sm text-gray-500 dark:text-gray-400 sm:shrink-0">
                {t('common.to')}
            </span>
            <LocalizedDatePicker
                id={endId}
                name={endName}
                value={endValue}
                onChange={onEndChange}
                min={computedEndMin}
                max={endMax}
                disabled={disabled}
                placeholder={t('calendar.endDate')}
                className="flex-1"
            />
        </div>
    );
}
