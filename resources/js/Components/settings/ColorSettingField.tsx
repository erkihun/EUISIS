import InputError from '@/Components/InputError';

type Props = {
    label: string;
    description?: string | null;
    value: string;
    error?: string;
    disabled?: boolean;
    onChange: (value: string) => void;
};

export default function ColorSettingField({
    label,
    description,
    value,
    error,
    disabled = false,
    onChange,
}: Props) {
    return (
        <div className="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-3 md:items-start">
            <div>
                <span className="text-sm font-medium text-gray-900 dark:text-slate-100">{label}</span>
                {description && (
                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{description}</p>
                )}
            </div>

            <div className="space-y-2 md:col-span-2">
                <div className="flex items-center gap-3">
                    <input
                        type="color"
                        value={value || '#2563EB'}
                        disabled={disabled}
                        onChange={(event) => onChange(event.target.value.toUpperCase())}
                        className="h-10 w-14 rounded-lg border border-gray-300 bg-white dark:border-slate-700 dark:bg-slate-950"
                    />
                    <input
                        type="text"
                        value={value}
                        disabled={disabled}
                        onChange={(event) => onChange(event.target.value.toUpperCase())}
                        className="w-full rounded-card border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-900 placeholder:text-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    />
                    <div className="h-10 w-10 rounded-card border border-gray-200 dark:border-slate-700" style={{ backgroundColor: value }} />
                </div>
                <InputError message={error} />
            </div>
        </div>
    );
}
