import FieldRow from '@/Components/settings/FieldRow';
import { EyeIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Input, StatusBadge } from '@euisis/ui';
import { useId, useState } from 'react';

type Props = {
    label: string;
    description?: string | null;
    configured: boolean;
    value: string;
    error?: string;
    disabled?: boolean;
    onChange: (value: string) => void;
};

/**
 * A write-only secret. The stored value is never sent to the browser: the field
 * shows whether one exists, and a blank entry keeps it.
 */
export default function SecretSettingField({ label, description, configured, value, error, disabled = false, onChange }: Props) {
    const { t } = useLocale();
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = error ? `${id}-error` : undefined;
    const [visible, setVisible] = useState(false);

    return (
        <FieldRow htmlFor={id} label={label} description={description} error={error} errorId={errorId}
            badge={<StatusBadge tone={configured ? 'success' : 'neutral'}>{configured ? t('settings.configured') : t('settings.notConfigured')}</StatusBadge>}>
            <div className="relative">
                <Input id={id} type={visible ? 'text' : 'password'} value={value} onChange={(event) => onChange(event.target.value)} disabled={disabled} autoComplete="new-password" spellCheck={false}
                    placeholder={configured ? t('settings.secretKeepPlaceholder') : t('settings.secretNewPlaceholder')}
                    aria-describedby={[hintId, errorId].filter(Boolean).join(' ')} aria-invalid={Boolean(error)} className="pr-10" />
                {value !== '' && (
                    <button type="button" onClick={() => setVisible((shown) => !shown)} aria-pressed={visible}
                        aria-label={visible ? t('settings.hideSecret') : t('settings.showSecret')} title={visible ? t('settings.hideSecret') : t('settings.showSecret')}
                        className="absolute inset-y-0 right-1 my-auto flex h-8 w-8 items-center justify-center rounded-[var(--radius-control)] text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]">
                        <EyeIcon className="h-4 w-4" aria-hidden="true" />
                    </button>
                )}
            </div>
            <div className="flex items-center justify-between gap-2">
                <p id={hintId} className="text-xs text-[color:var(--app-muted-foreground)]">{configured ? t('settings.secretHint') : t('settings.secretNewHint')}</p>
                {value !== '' && !disabled && (
                    <button type="button" onClick={() => { onChange(''); setVisible(false); }} className="shrink-0 text-xs font-medium text-[color:var(--color-primary)] hover:underline">
                        {t('common.clear')}
                    </button>
                )}
            </div>
        </FieldRow>
    );
}
