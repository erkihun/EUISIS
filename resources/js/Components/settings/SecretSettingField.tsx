import Button from '@/Components/Button';
import InputError from '@/Components/InputError';
import { useLocale } from '@/hooks/useLocale';

type Props = {
    label: string;
    description?: string | null;
    configured: boolean;
    value: string;
    error?: string;
    disabled?: boolean;
    onChange: (value: string) => void;
};

export default function SecretSettingField({
    label,
    description,
    configured,
    value,
    error,
    disabled = false,
    onChange,
}: Props) {
    const { t } = useLocale();

    return (
        <div className="grid grid-cols-1 gap-3 px-5 py-4 md:grid-cols-3 md:items-start">
            <div>
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-900 dark:text-slate-100">{label}</span>
                    <span
                        className={[
                            'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                            configured
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                : 'bg-gray-200 text-gray-600 dark:bg-slate-800 dark:text-slate-300',
                        ].join(' ')}
                    >
                        {configured ? t('settings.configured') : t('settings.notConfigured')}
                    </span>
                </div>
                {description && (
                    <p className="mt-1 text-xs text-gray-500 dark:text-slate-400">{description}</p>
                )}
            </div>

            <div className="space-y-2 md:col-span-2">
                <input
                    type="password"
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    disabled={disabled}
                    autoComplete="new-password"
                    className="w-full rounded-card border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 placeholder:text-gray-400 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    placeholder={t('settings.secretHidden')}
                />
                <div className="flex items-center justify-between gap-2">
                    <span className="text-xs text-gray-500 dark:text-slate-400">{t('settings.secretHint')}</span>
                    {configured && ! disabled && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="xs"
                            onClick={() => onChange('')}
                        >
                            {t('common.clear')}
                        </Button>
                    )}
                </div>
                <InputError message={error} />
            </div>
        </div>
    );
}
