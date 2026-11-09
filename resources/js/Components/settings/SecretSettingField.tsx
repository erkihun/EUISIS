import FieldRow from '@/Components/settings/FieldRow';
import { EyeIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Input, StatusBadge } from '@euisis/ui';
import { useId, useState } from 'react';

const linkCls = 'shrink-0 text-xs font-medium text-primary hover:underline';

type Props = {
    label: string;
    description?: string | null;
    configured: boolean;
    /** null: the saved value is removed on save. */
    value: string | null;
    error?: string;
    disabled?: boolean;
    /** Offer "Remove saved value"; only where the server accepts the removal. */
    removable?: boolean;
    onChange: (value: string | null) => void;
};

/**
 * A write-only secret. The stored value is never sent to the browser: the field
 * shows whether one exists, and a blank entry keeps it. Where `removable`, the
 * saved value can be marked for removal instead (the value becomes null).
 */
export default function SecretSettingField({ label, description, configured, value, error, disabled = false, removable = false, onChange }: Props) {
    const { t } = useLocale();
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = error ? `${id}-error` : undefined;
    const [visible, setVisible] = useState(false);
    const removing = value === null;
    const text = value ?? '';

    return (
        <FieldRow htmlFor={id} label={label} description={description} error={error} errorId={errorId}
            badge={removing
                ? <StatusBadge tone="warning">{t('settings.secretRemoving')}</StatusBadge>
                : <StatusBadge tone={configured ? 'success' : 'neutral'}>{configured ? t('settings.configured') : t('settings.notConfigured')}</StatusBadge>}>
            <div className="relative">
                <Input id={id} type={visible ? 'text' : 'password'} value={text} onChange={(event) => onChange(event.target.value)} disabled={disabled || removing} autoComplete="new-password" spellCheck={false}
                    placeholder={removing ? '' : configured ? t('settings.secretKeepPlaceholder') : t('settings.secretNewPlaceholder')}
                    aria-describedby={[hintId, errorId].filter(Boolean).join(' ')} aria-invalid={Boolean(error)} className="pr-10" />
                {text !== '' && (
                    <button type="button" onClick={() => setVisible((shown) => !shown)} aria-pressed={visible}
                        aria-label={visible ? t('settings.hideSecret') : t('settings.showSecret')} title={visible ? t('settings.hideSecret') : t('settings.showSecret')}
                        className="absolute inset-y-0 right-1 my-auto flex h-8 w-8 items-center justify-center rounded-control text-(--app-muted-foreground) hover:text-(--app-foreground) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary">
                        <EyeIcon className="h-4 w-4" aria-hidden="true" />
                    </button>
                )}
            </div>
            <div className="flex items-center justify-between gap-2">
                <p id={hintId} className="text-xs text-(--app-muted-foreground)">
                    {removing ? t('settings.secretRemoveHint') : configured ? t('settings.secretHint') : t('settings.secretNewHint')}
                </p>
                {!disabled && (removing ? (
                    <button type="button" onClick={() => onChange('')} className={linkCls}>{t('settings.secretKeep')}</button>
                ) : text !== '' ? (
                    <button type="button" onClick={() => { onChange(''); setVisible(false); }} className={linkCls}>{t('common.clear')}</button>
                ) : configured && removable && (
                    <button type="button" onClick={() => onChange(null)} className={linkCls}>{t('settings.secretRemove')}</button>
                ))}
            </div>
        </FieldRow>
    );
}
