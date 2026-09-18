import InputError from '@/Components/InputError';
import { useState, useRef, useEffect } from 'react';

type Props = {
    label: string;
    description?: string | null;
    value: string;
    error?: string;
    disabled?: boolean;
    onChange: (value: string) => void;
};

const TIMEZONES: { region: string; zones: string[] }[] = [
    {
        region: 'Africa',
        zones: [
            'Africa/Abidjan', 'Africa/Accra', 'Africa/Addis_Ababa', 'Africa/Algiers',
            'Africa/Cairo', 'Africa/Casablanca', 'Africa/Dar_es_Salaam', 'Africa/Djibouti',
            'Africa/Harare', 'Africa/Johannesburg', 'Africa/Kampala', 'Africa/Khartoum',
            'Africa/Lagos', 'Africa/Lusaka', 'Africa/Maputo', 'Africa/Mogadishu',
            'Africa/Nairobi', 'Africa/Ndjamena', 'Africa/Tripoli', 'Africa/Tunis',
        ],
    },
    {
        region: 'Europe',
        zones: [
            'Europe/Amsterdam', 'Europe/Athens', 'Europe/Belgrade', 'Europe/Berlin',
            'Europe/Brussels', 'Europe/Budapest', 'Europe/Copenhagen', 'Europe/Dublin',
            'Europe/Helsinki', 'Europe/Istanbul', 'Europe/Kiev', 'Europe/Lisbon',
            'Europe/London', 'Europe/Madrid', 'Europe/Moscow', 'Europe/Oslo',
            'Europe/Paris', 'Europe/Prague', 'Europe/Rome', 'Europe/Sofia',
            'Europe/Stockholm', 'Europe/Vienna', 'Europe/Warsaw', 'Europe/Zurich',
        ],
    },
    {
        region: 'Asia',
        zones: [
            'Asia/Amman', 'Asia/Baghdad', 'Asia/Bahrain', 'Asia/Bangkok',
            'Asia/Beirut', 'Asia/Colombo', 'Asia/Damascus', 'Asia/Dhaka',
            'Asia/Dubai', 'Asia/Ho_Chi_Minh', 'Asia/Hong_Kong', 'Asia/Jakarta',
            'Asia/Jerusalem', 'Asia/Kabul', 'Asia/Karachi', 'Asia/Kathmandu',
            'Asia/Kolkata', 'Asia/Kuala_Lumpur', 'Asia/Kuwait', 'Asia/Muscat',
            'Asia/Nicosia', 'Asia/Qatar', 'Asia/Riyadh', 'Asia/Seoul',
            'Asia/Shanghai', 'Asia/Singapore', 'Asia/Taipei', 'Asia/Tehran',
            'Asia/Tokyo', 'Asia/Yangon',
        ],
    },
    {
        region: 'America',
        zones: [
            'America/Anchorage', 'America/Argentina/Buenos_Aires', 'America/Bogota',
            'America/Chicago', 'America/Denver', 'America/Halifax', 'America/Lima',
            'America/Los_Angeles', 'America/Mexico_City', 'America/New_York',
            'America/Phoenix', 'America/Sao_Paulo', 'America/Santiago',
            'America/Toronto', 'America/Vancouver',
        ],
    },
    {
        region: 'Pacific / Atlantic',
        zones: [
            'Atlantic/Reykjavik', 'Pacific/Auckland', 'Pacific/Fiji',
            'Pacific/Honolulu', 'Pacific/Sydney',
        ],
    },
    {
        region: 'UTC',
        zones: ['UTC'],
    },
];

const ALL_ZONES = TIMEZONES.flatMap((g) => g.zones);

export default function TimezoneSettingField({
    label,
    description,
    value,
    error,
    disabled = false,
    onChange,
}: Props) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    const filtered = query.trim()
        ? ALL_ZONES.filter((tz) => tz.toLowerCase().includes(query.toLowerCase()))
        : ALL_ZONES;

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                setOpen(false);
                setQuery('');
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, []);

    const selectZone = (tz: string) => {
        onChange(tz);
        setOpen(false);
        setQuery('');
    };

    const inputCls =
        'w-full rounded-card border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

    return (
        <div className="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-3 md:items-start">
            <div>
                <span className="text-sm font-medium text-gray-900 dark:text-slate-100">{label}</span>
                {description && (
                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{description}</p>
                )}
            </div>

            <div className="space-y-2 md:col-span-2" ref={containerRef}>
                {/* Current value display + open button */}
                <button
                    type="button"
                    disabled={disabled}
                    onClick={() => { if (!disabled) setOpen((p) => !p); }}
                    className={[
                        inputCls,
                        'flex items-center justify-between text-left',
                    ].join(' ')}
                >
                    <span>{value || <span className="text-gray-400">Select timezone…</span>}</span>
                    <svg className="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="m19 9-7 7-7-7" />
                    </svg>
                </button>

                {open && (
                    <div className="relative z-50">
                        <div className="absolute top-0 left-0 right-0 rounded-card border border-gray-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">
                            {/* Search input */}
                            <div className="border-b border-gray-100 p-2 dark:border-slate-800">
                                <div className="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 dark:border-slate-700 dark:bg-slate-950">
                                    <svg className="h-4 w-4 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                                    </svg>
                                    <input
                                        autoFocus
                                        type="text"
                                        placeholder="Search timezone…"
                                        value={query}
                                        onChange={(e) => setQuery(e.target.value)}
                                        className="flex-1 bg-transparent text-sm text-gray-900 placeholder:text-gray-400 focus:outline-none dark:text-slate-100"
                                    />
                                </div>
                            </div>

                            {/* Options list */}
                            <div className="max-h-56 overflow-y-auto">
                                {query.trim() ? (
                                    filtered.length === 0 ? (
                                        <p className="px-4 py-3 text-sm text-gray-400 dark:text-slate-500">No timezones found.</p>
                                    ) : (
                                        filtered.map((tz) => (
                                            <button
                                                key={tz}
                                                type="button"
                                                onClick={() => selectZone(tz)}
                                                className={[
                                                    'flex w-full items-center px-4 py-2 text-left text-sm transition-colors',
                                                    tz === value
                                                        ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-500/10 dark:text-[color:var(--color-primary)]'
                                                        : 'text-gray-800 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-800',
                                                ].join(' ')}
                                            >
                                                {tz}
                                                {tz === value && (
                                                    <svg className="ml-auto h-4 w-4 text-[color:var(--color-primary)] dark:text-[color:var(--color-primary)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                    </svg>
                                                )}
                                            </button>
                                        ))
                                    )
                                ) : (
                                    TIMEZONES.map((group) => (
                                        <div key={group.region}>
                                            <div className="sticky top-0 bg-gray-50 px-4 py-1 text-[10px] font-semibold text-gray-400 dark:bg-slate-800 dark:text-slate-500">
                                                {group.region}
                                            </div>
                                            {group.zones.map((tz) => (
                                                <button
                                                    key={tz}
                                                    type="button"
                                                    onClick={() => selectZone(tz)}
                                                    className={[
                                                        'flex w-full items-center px-4 py-2 text-left text-sm transition-colors',
                                                        tz === value
                                                            ? 'bg-blue-50 font-medium text-blue-700 dark:bg-blue-500/10 dark:text-[color:var(--color-primary)]'
                                                            : 'text-gray-800 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-800',
                                                    ].join(' ')}
                                                >
                                                    {tz}
                                                    {tz === value && (
                                                        <svg className="ml-auto h-4 w-4 text-[color:var(--color-primary)] dark:text-[color:var(--color-primary)]" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                                        </svg>
                                                    )}
                                                </button>
                                            ))}
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                )}

                <InputError message={error} />
            </div>
        </div>
    );
}
