import { ChangeEvent, useRef } from 'react';
import { Button, buttonClassName } from '@euisis/ui';
import { X } from '@/Components/Icons';

function formatSize(bytes: number): string {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * The one way the public site takes several files: a labelled button that
 * opens the picker, then the chosen files listed with a remove control each.
 * The native "Choose files / No file chosen" control is never shown — its
 * wording is the browser's, not the site's, and it has no remove action.
 */
export default function MultiFileUpload({ id, files, onChange, accept, max, chooseLabel, removeLabel, describedBy, disabled = false }: {
    id: string;
    files: File[];
    onChange: (files: File[]) => void;
    accept?: string;
    /** Most files accepted; the button disables once reached. */
    max?: number;
    chooseLabel: string;
    removeLabel: string;
    describedBy?: string;
    disabled?: boolean;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const full = max !== undefined && files.length >= max;

    function add(event: ChangeEvent<HTMLInputElement>) {
        const picked = Array.from(event.target.files ?? []);
        const next = [...files, ...picked];

        onChange(max === undefined ? next : next.slice(0, max));

        // Reset so choosing the same file again after removing it still fires.
        if (inputRef.current) inputRef.current.value = '';
    }

    return (
        <div className="space-y-2">
            <Button
                type="button"
                variant="outline"
                className="w-full border-dashed"
                aria-describedby={describedBy}
                disabled={disabled || full}
                onClick={() => inputRef.current?.click()}
            >
                {chooseLabel}
            </Button>
            <input
                ref={inputRef}
                id={id}
                type="file"
                multiple
                accept={accept}
                tabIndex={-1}
                aria-hidden="true"
                className="hidden"
                onChange={add}
            />

            {files.length > 0 && (
                <ul className="divide-y divide-gray-200 rounded-card border border-gray-200 dark:divide-slate-800 dark:border-slate-800">
                    {files.map((file, index) => (
                        <li key={`${file.name}-${index}`} className="flex items-center justify-between gap-2 px-3 py-2 text-sm text-gray-700 dark:text-slate-300">
                            <span className="min-w-0 truncate">{file.name}</span>
                            <span className="flex shrink-0 items-center gap-2">
                                <span className="text-xs text-gray-500 dark:text-slate-400">{formatSize(file.size)}</span>
                                <button
                                    type="button"
                                    onClick={() => onChange(files.filter((_, i) => i !== index))}
                                    className={buttonClassName({ variant: 'ghost', size: 'icon', className: 'h-8 w-8' })}
                                    aria-label={`${removeLabel}: ${file.name}`}
                                >
                                    <X className="h-4 w-4" aria-hidden="true" />
                                </button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
