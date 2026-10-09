import { useRef } from 'react';
import { useLocale } from '@/hooks/useLocale';

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

export default function GregorianDatePicker({
    value,
    onChange,
    min,
    max,
    disabled = false,
    required = false,
    className = '',
    placeholder,
    id,
    name,
}: Props) {
    const { locale, t } = useLocale();
    const inputRef = useRef<HTMLInputElement>(null);

    const defaultPlaceholder = locale === 'am' ? 'ቀን ይምረጡ' : t('calendar.selectDate');

    function formatDisplay(iso: string): string {
        if (!iso) return '';
        const [y, m, d] = iso.split('-').map(Number);
        const date = new Date(Date.UTC(y, m - 1, d));
        if (isNaN(date.getTime())) return iso;
        return date.toLocaleDateString(locale === 'am' ? 'am-ET' : 'en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            timeZone: 'UTC',
        });
    }

    function handleButtonClick() {
        if (disabled) return;
        try {
            inputRef.current?.showPicker();
        } catch {
            inputRef.current?.focus();
            inputRef.current?.click();
        }
    }

    return (
        <div className="relative w-full">
            {/* Hidden native date input — provides the actual picker */}
            <input
                ref={inputRef}
                type="date"
                id={id}
                name={name}
                value={value}
                min={min}
                max={max}
                required={required}
                disabled={disabled}
                onChange={(e) => onChange(e.target.value)}
                className="sr-only"
                tabIndex={-1}
                aria-hidden="true"
            />

            {/* Styled trigger button */}
            <button
                type="button"
                disabled={disabled}
                onClick={handleButtonClick}
                className={`flex w-full items-center justify-between rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:focus:border-indigo-600 dark:focus:ring-indigo-600 ${disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'} ${className}`}
            >
                <span className={value ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400 dark:text-gray-500'}>
                    {value ? formatDisplay(value) : (placeholder ?? defaultPlaceholder)}
                </span>
                <svg className="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </button>
        </div>
    );
}
