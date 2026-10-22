import FieldRow from '@/Components/settings/FieldRow';
import { Upload, X } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { Button, StatusBadge } from '@euisis/ui';
import { useEffect, useId, useRef, useState, type ChangeEvent } from 'react';

/** Form value meaning "delete the saved image on save"; the page sends it as `remove_assets[]`. */
export const REMOVE_ASSET = '__remove_asset__';

type Props = {
    label: string;
    description?: string | null;
    previewUrl?: string | null;
    configured: boolean;
    error?: string;
    disabled?: boolean;
    accept?: string;
    onChange: (file: File | null) => void;
    /** The saved image may be taken down (the server accepts `remove_assets` for it). */
    removable?: boolean;
    markedForRemoval?: boolean;
    onRemoveChange?: (remove: boolean) => void;
};

/** An uploaded asset: the current image, a replace button, and the chosen file until it is saved. */
export default function ImageSettingField({ label, description, previewUrl, configured, error, disabled = false, accept = '.jpg,.jpeg,.png,.webp,.ico', onChange, removable = false, markedForRemoval = false, onRemoveChange }: Props) {
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

    const shown = localPreview ?? (markedForRemoval ? null : previewUrl);
    const canRemove = removable && configured && !file && Boolean(onRemoveChange);

    return (
        <FieldRow htmlFor={id} label={label} description={description} error={error} errorId={errorId}
            badge={<StatusBadge tone={configured ? 'success' : 'neutral'}>{configured ? t('settings.configured') : t('settings.notConfigured')}</StatusBadge>}>
            <div className="flex items-center gap-4">
                <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-card border border-dashed border-(--app-border-strong) bg-(--app-surface-muted) p-1.5">
                    {shown
                        ? <img src={shown} alt={t('settings.currentImage').replace('{{name}}', label)} className="h-full w-full object-contain" />
                        : <Upload className="h-5 w-5 text-(--app-muted-foreground)" aria-hidden="true" />}
                </div>
                <div className="min-w-0 flex-1 space-y-2">
                    <input ref={inputRef} id={id} type="file" accept={accept} disabled={disabled} onChange={choose} className="sr-only" aria-describedby={errorId} />
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" size="sm" disabled={disabled} onClick={() => { if (markedForRemoval) onRemoveChange?.(false); inputRef.current?.click(); }}>
                            {configured || file ? t('settings.replaceFile') : t('settings.chooseFile')}
                        </Button>
                        {canRemove && !markedForRemoval && (
                            <Button type="button" variant="outline" size="sm" disabled={disabled} onClick={() => onRemoveChange?.(true)}>
                                {t('settings.removeImage')}
                            </Button>
                        )}
                    </div>
                    {canRemove && markedForRemoval ? (
                        <p className="flex min-w-0 items-center gap-2 text-xs">
                            <StatusBadge tone="warning">{t('settings.removedOnSave')}</StatusBadge>
                            <button type="button" onClick={() => onRemoveChange?.(false)} className="font-medium text-primary underline-offset-4 hover:underline focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary">
                                {t('settings.keepImage')}
                            </button>
                        </p>
                    ) : file ? (
                        <p className="flex min-w-0 items-center gap-2 text-xs">
                            <StatusBadge tone="info">{t('settings.pendingUpload')}</StatusBadge>
                            <span className="min-w-0 truncate text-(--app-foreground)">{file.name}</span>
                            <button type="button" onClick={clear} aria-label={t('settings.removeChosenFile')} title={t('settings.removeChosenFile')}
                                className="shrink-0 rounded-sm p-0.5 text-(--app-muted-foreground) hover:text-(--app-foreground) focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary">
                                <X className="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                        </p>
                    ) : (
                        <p className="text-xs text-(--app-muted-foreground)">{accept.split(',').map((type) => type.replace(/^\./, '').replace('image/', '').toUpperCase()).filter((type, index, all) => all.indexOf(type) === index).join(', ')}</p>
                    )}
                </div>
            </div>
        </FieldRow>
    );
}
