import InputError from '@/Components/InputError';

type Option = {
    value: string;
    label: string;
};

type Props = {
    label: string;
    description?: string | null;
    value: string[];
    options: Option[];
    error?: string;
    disabled?: boolean;
    onChange: (value: string[]) => void;
};

export default function LocaleSettingField({
    label,
    description,
    value,
    options,
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

            <div className="space-y-3 md:col-span-2">
                <div className="flex flex-wrap gap-3">
                    {options.map((option) => {
                        const checked = value.includes(option.value);

                        return (
                            <label
                                key={option.value}
                                className="inline-flex items-center gap-2 rounded-card border border-gray-200 px-3 py-2 text-sm text-gray-700 dark:border-slate-700 dark:text-slate-200"
                            >
                                <input
                                    type="checkbox"
                                    checked={checked}
                                    disabled={disabled}
                                    onChange={(event) => {
                                        if (event.target.checked) {
                                            onChange([...value, option.value]);
                                        } else {
                                            onChange(value.filter((entry) => entry !== option.value));
                                        }
                                    }}
                                />
                                <span>{option.label}</span>
                            </label>
                        );
                    })}
                </div>
                <InputError message={error} />
            </div>
        </div>
    );
}
