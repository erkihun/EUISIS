import InputError from '@/Components/InputError';
import { useLocale } from '@/hooks/useLocale';
import { useRef, useState } from 'react';

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

export default function ImageSettingField({
    label,
    description,
    previewUrl,
    configured,
    error,
    disabled = false,
    accept = '.jpg,.jpeg,.png,.webp,.ico',
    onChange,
}: Props) {
    const { t } = useLocale();
    const inputRef = useRef<HTMLInputElement>(null);
    const [localPreview, setLocalPreview] = useState<string | null>(null);
    const [fileName, setFileName] = useState<string | null>(null);

    const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0] ?? null;
        onChange(file);

        if (file) {
            setFileName(file.name);
            const reader = new FileReader();
            reader.onload = (e) => {
                setLocalPreview(e.target?.result as string ?? null);
            };
            reader.readAsDataURL(file);
        } else {
            setLocalPreview(null);
            setFileName(null);
        }
    };

    const clearSelection = () => {
        onChange(null);
        setLocalPreview(null);
        setFileName(null);
        if (inputRef.current) {
            inputRef.current.value = '';
        }
    };

    const displayUrl = localPreview ?? previewUrl;

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

            <div className="space-y-3 md:col-span-2">
                <div className="flex items-center gap-4">
                    {/* Preview box */}
                    <div className="relative flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-dashed border-gray-300 bg-gray-50 dark:border-slate-700 dark:bg-slate-950">
                        {displayUrl ? (
                            <img src={displayUrl} alt="" className="h-full w-full object-contain" />
                        ) : (
                            <span className="text-xs text-gray-400 dark:text-slate-500">{t('settings.preview')}</span>
                        )}
                        {localPreview && (
                            <span className="absolute right-1 top-1 rounded-full bg-blue-600 px-1 py-0.5 text-[9px] font-semibold text-white">
                                NEW
                            </span>
                        )}
                    </div>

                    {/* File input area */}
                    <div className="flex-1 space-y-2">
                        <input
                            ref={inputRef}
                            type="file"
                            accept={accept}
                            disabled={disabled}
                            onChange={handleChange}
                            className="block w-full text-sm text-gray-600 file:mr-4 file:rounded-xl file:border-0 file:bg-blue-50 file:px-4 file:py-2 file:text-sm file:font-medium file:text-blue-700 hover:file:bg-blue-100 disabled:cursor-not-allowed disabled:opacity-60 dark:text-slate-300 dark:file:bg-slate-800 dark:file:text-slate-200"
                        />
                        {fileName && (
                            <div className="flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 dark:border-blue-500/30 dark:bg-blue-500/10">
                                <svg className="h-3.5 w-3.5 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13" />
                                </svg>
                                <span className="flex-1 truncate text-xs text-blue-700 dark:text-blue-300">{fileName}</span>
                                <button
                                    type="button"
                                    onClick={clearSelection}
                                    className="text-blue-400 hover:text-blue-600 dark:text-blue-500 dark:hover:text-blue-300"
                                >
                                    <svg className="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18 18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                        )}
                    </div>
                </div>
                <InputError message={error} />
            </div>
        </div>
    );
}
