import FieldRow from '@/Components/settings/FieldRow';
import { Upload, X } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Button, StatusBadge } from '@euisis/ui';
import { useEffect, useId, useRef, useState, type ChangeEvent } from 'react';

type Props = {
    label: string;
    description?: string | null;
    previewUrl?: string | null;
    configured: boolean;
    error?: string;
    disabled?: boolean;
    accept?: string;
    onChange: (file: File | null) => void;
};

/** An uploaded asset: the current image, a replace button, and the chosen file until it is saved. */
export default function ImageSettingField({ label, description, previewUrl, configured, error, disabled = false, accept = '.jpg,.jpeg,.png,.webp,.ico', onChange }: Props) {
    const { t } = useLocale();
    const id = useId();
    const errorId = error ? `${id}-error` : undefined;
    const inputRef = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [localPreview, setLocalPreview] = useState<string | null>(null);

    useEffect(() => () => { if (localPreview) URL.revokeObjectURL(localPreview); }, [localPreview]);

    const choose = (event: ChangeEvent<HTMLInputElement>) => {
        const next = event.target.files?.[0] ?? null;
        setFile(next);
        setLocalPreview(next ? URL.createObjectURL(next) : null);
        onChange(next);
    };

    const clear = () => {
        setFile(null);
        setLocalPreview(null);
        onChange(null);
        if (inputRef.current) inputRef.current.value = '';
    };

    const shown = localPreview ?? previewUrl;

    return (
        <FieldRow htmlFor={id} label={label} description={description} error={error} errorId={errorId}
            badge={<StatusBadge tone={configured ? 'success' : 'neutral'}>{configured ? t('settings.configured') : t('settings.notConfigured')}</StatusBadge>}>
            <div className="flex items-center gap-4">
                <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-[var(--radius-card)] border border-dashed border-[color:var(--app-border-strong)] bg-[color:var(--app-surface-muted)] p-1.5">
                    {shown
                        ? <img src={shown} alt={t('settings.currentImage').replace('{{name}}', label)} className="h-full w-full object-contain" />
                        : <Upload className="h-5 w-5 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />}
                </div>
                <div className="min-w-0 flex-1 space-y-2">
                    <input ref={inputRef} id={id} type="file" accept={accept} disabled={disabled} onChange={choose} className="sr-only" aria-describedby={errorId} />
                    <Button type="button" variant="outline" size="sm" disabled={disabled} onClick={() => inputRef.current?.click()}>
                        {configured || file ? t('settings.replaceFile') : t('settings.chooseFile')}
                    </Button>
                    {file ? (
                        <p className="flex min-w-0 items-center gap-2 text-xs">
                            <StatusBadge tone="info">{t('settings.pendingUpload')}</StatusBadge>
                            <span className="min-w-0 truncate text-[color:var(--app-foreground)]">{file.name}</span>
                            <button type="button" onClick={clear} aria-label={t('settings.removeChosenFile')} title={t('settings.removeChosenFile')}
                                className="shrink-0 rounded p-0.5 text-[color:var(--app-muted-foreground)] hover:text-[color:var(--app-foreground)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)]">
                                <X className="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                        </p>
                    ) : (
                        <p className="text-xs text-[color:var(--app-muted-foreground)]">{accept.split(',').map((type) => type.replace(/^\./, '').replace('image/', '').toUpperCase()).filter((type, index, all) => all.indexOf(type) === index).join(', ')}</p>
                    )}
                </div>
            </div>
        </FieldRow>
    );
}
