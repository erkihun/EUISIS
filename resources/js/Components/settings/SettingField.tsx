import InputError from '@/Components/InputError';
import ColorSettingField from '@/Components/settings/ColorSettingField';
import ImageSettingField from '@/Components/settings/ImageSettingField';
import LocaleSettingField from '@/Components/settings/LocaleSettingField';
import SecretSettingField from '@/Components/settings/SecretSettingField';
import TimezoneSettingField from '@/Components/settings/TimezoneSettingField';
import { useLocale } from '@/hooks/useLocale';
import type { SettingsField } from '@/lib/settings';

type ScalarValue = string | number | boolean | string[] | File | null;

type Props = {
    field: SettingsField;
    locale: 'en' | 'am';
    value: ScalarValue;
    error?: string;
    disabled?: boolean;
    onChange: (value: ScalarValue) => void;
};

const inputClassName =
    'w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

export default function SettingField({ field, locale, value, error, disabled = false, onChange }: Props) {
    const { t } = useLocale();
    const label = (locale === 'am' ? field.label_am : field.label_en) ?? field.label_en ?? field.key;
    const description = (locale === 'am' ? field.description_am : field.description_en) ?? field.description_en ?? null;

    if (field.is_encrypted) {
        return (
            <SecretSettingField
                label={label}
                description={description}
                configured={field.configured}
                value={(value as string) ?? ''}
                error={error}
                disabled={disabled}
                onChange={(nextValue) => onChange(nextValue)}
            />
        );
    }

    if (field.type === 'color') {
        return (
            <ColorSettingField
                label={label}
                description={description}
                value={(value as string) ?? ''}
                error={error}
                disabled={disabled}
                onChange={(nextValue) => onChange(nextValue)}
            />
        );
    }

    if (field.type === 'image' || field.type === 'file') {
        return (
            <ImageSettingField
                label={label}
                description={description}
                previewUrl={field.asset_url}
                configured={field.configured}
                error={error}
                disabled={disabled}
                accept={field.key === 'seal' ? '.png,image/png' : undefined}
                onChange={(file) => onChange(file)}
            />
        );
    }

    if (field.type === 'timezone' || field.key === 'timezone') {
        return (
            <TimezoneSettingField
                label={label}
                description={description}
                value={(value as string) ?? ''}
                error={error}
                disabled={disabled}
                onChange={(nextValue) => onChange(nextValue)}
            />
        );
    }

    if (field.key === 'supported_locales') {
        return (
            <LocaleSettingField
                label={label}
                description={description}
                value={Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []}
                options={[
                    { value: 'en', label: t('common.english') },
                    { value: 'am', label: t('common.amharic') },
                ]}
                error={error}
                disabled={disabled}
                onChange={(nextValue) => onChange(nextValue)}
            />
        );
    }

    return (
        <div className="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-3 md:items-start">
            <div>
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-900 dark:text-slate-100">{label}</span>
                    {field.is_required && (
                        <span className="rounded-full bg-orange-100 px-2 py-0.5 text-[11px] font-semibold text-orange-700 dark:bg-orange-900/40 dark:text-orange-300">
                            {t('settings.required')}
                        </span>
                    )}
                </div>
                {description && (
                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{description}</p>
                )}
            </div>

            <div className="space-y-2 md:col-span-2">
                {renderFieldControl(field, value, disabled, onChange)}
                <InputError message={error} />
            </div>
        </div>
    );
}

function renderFieldControl(
    field: SettingsField,
    value: ScalarValue,
    disabled: boolean,
    onChange: (value: ScalarValue) => void,
) {
    if (field.type === 'boolean') {
        return (
            <button
                type="button"
                role="switch"
                aria-checked={Boolean(value)}
                disabled={disabled}
                onClick={() => onChange(! Boolean(value))}
                className={[
                    'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                    Boolean(value) ? 'bg-blue-600' : 'bg-gray-300 dark:bg-slate-700',
                    disabled ? 'cursor-not-allowed opacity-60' : '',
                ].join(' ')}
            >
                <span
                    className={[
                        'inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform',
                        Boolean(value) ? 'translate-x-5' : 'translate-x-0.5',
                    ].join(' ')}
                />
            </button>
        );
    }

    if (field.type === 'select' && Array.isArray(field.options)) {
        return (
            <div className="relative">
                <select
                    value={(value as string) ?? ''}
                    disabled={disabled}
                    onChange={(event) => onChange(event.target.value)}
                    className={`${inputClassName} appearance-none pr-9`}
                >
                    {field.options.map((option) => (
                        <option key={option} value={option}>
                            {option}
                        </option>
                    ))}
                </select>
                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-gray-400 dark:text-slate-500">
                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="m19 9-7 7-7-7" />
                    </svg>
                </span>
            </div>
        );
    }

    if (field.type === 'integer') {
        return (
            <input
                type="number"
                value={value === null || value === undefined ? '' : String(value)}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value === '' ? null : Number(event.target.value))}
                className={inputClassName}
            />
        );
    }

    if (field.type === 'text') {
        return (
            <textarea
                value={(value as string) ?? ''}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
                className={`${inputClassName} min-h-[96px]`}
            />
        );
    }

    const inputType = field.type === 'email'
        ? 'email'
        : field.type === 'url'
            ? 'url'
            : field.type === 'phone'
                ? 'tel'
                : 'text';

    return (
        <input
            type={inputType}
            value={Array.isArray(value) ? value.join(', ') : ((value as string | number | null) ?? '')}
            disabled={disabled}
            onChange={(event) => {
                if (field.type === 'json' || field.type === 'multiselect') {
                    onChange(
                        event.target.value
                            .split(',')
                            .map((entry) => entry.trim())
                            .filter((entry) => entry.length > 0),
                    );

                    return;
                }

                onChange(event.target.value);
            }}
            className={inputClassName}
        />
    );
}
