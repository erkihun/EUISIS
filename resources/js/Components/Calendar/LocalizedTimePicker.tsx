import { useLocale } from '@/hooks/useLocale';

// ── Ethiopian time conversion ──────────────────────────────────────────────
// Ethiopian clock is offset +6 hours from Gregorian (day starts at dawn ~6 AM).
// Gregorian 7:00 AM = Ethiopian 1:00 ጥዋት
// Gregorian 6:00 PM = Ethiopian 12:00 ምሽት
// Gregorian 7:00 PM = Ethiopian 1:00 ምሽት

type Period = 'ጥዋት' | 'ምሽት';

function gregToEth(hhmm: string): { ethHour: number; minute: number; period: Period } {
    const [h = 7, m = 0] = (hhmm || '07:00').split(':').map(Number);
    const ethHour = ((h + 18) % 24) % 12 || 12;
    const period: Period = h >= 6 && h < 18 ? 'ጥዋት' : 'ምሽት';
    return { ethHour, minute: m, period };
}

function ethToGreg(ethHour: number, minute: number, period: Period): string {
    const h = period === 'ጥዋት' ? (ethHour + 6) % 24 : (ethHour + 18) % 24;
    return `${String(h).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

// ── Ethiopian time picker (dropdowns) ─────────────────────────────────────
function EthiopianTimePicker({
    value,
    onChange,
    disabled,
    className,
}: {
    value: string;
    onChange: (hhmm: string) => void;
    disabled?: boolean;
    className?: string;
}) {
    const { ethHour, minute, period } = gregToEth(value);

    const selCls = `rounded border border-gray-300 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-50 ${className ?? ''}`;

    function patch(update: Partial<{ ethHour: number; minute: number; period: Period }>) {
        const next = { ethHour, minute, period, ...update };
        onChange(ethToGreg(next.ethHour, next.minute, next.period));
    }

    return (
        <div className="flex items-center gap-1">
            {/* Hour 1–12 */}
            <select
                disabled={disabled}
                value={ethHour}
                onChange={e => patch({ ethHour: Number(e.target.value) })}
                className={selCls}
            >
                {Array.from({ length: 12 }, (_, i) => i + 1).map(h => (
                    <option key={h} value={h}>{h}</option>
                ))}
            </select>

            <span className="font-medium text-gray-500 dark:text-slate-400">:</span>

            {/* Minute 0–59 */}
            <select
                disabled={disabled}
                value={minute}
                onChange={e => patch({ minute: Number(e.target.value) })}
                className={selCls}
            >
                {Array.from({ length: 60 }, (_, i) => i).map(m => (
                    <option key={m} value={m}>{String(m).padStart(2, '0')}</option>
                ))}
            </select>

            {/* Period */}
            <select
                disabled={disabled}
                value={period}
                onChange={e => patch({ period: e.target.value as Period })}
                className={selCls}
            >
                <option value="ጥዋት">ጥዋት</option>
                <option value="ምሽት">ምሽት</option>
            </select>
        </div>
    );
}

// ── Public component — auto-switches based on locale ───────────────────────
export default function LocalizedTimePicker({
    value,
    onChange,
    disabled,
    className,
}: {
    value: string;
    onChange: (hhmm: string) => void;
    disabled?: boolean;
    className?: string;
}) {
    const { locale } = useLocale();

    if (locale === 'am') {
        return (
            <EthiopianTimePicker
                value={value}
                onChange={onChange}
                disabled={disabled}
                className={className}
            />
        );
    }

    return (
        <input
            type="time"
            value={value}
            onChange={e => onChange(e.target.value)}
            disabled={disabled}
            className={className ?? 'rounded border border-gray-300 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-50'}
        />
    );
}
