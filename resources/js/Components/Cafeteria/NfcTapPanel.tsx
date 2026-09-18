import { useCallback, useEffect, useRef, useState } from 'react';
import { NfcIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';

/**
 * Web NFC is Chromium-on-Android only. Everything else must fall back to the QR
 * scanner or to a dedicated terminal application — we never pretend to have a
 * reader we do not have.
 */
type ReaderState = 'idle' | 'waiting' | 'detected' | 'verifying' | 'unsupported' | 'error';

/** Minimal shape of the Web NFC reader we rely on. */
type NdefReaderLike = {
    scan: (options?: { signal?: AbortSignal }) => Promise<void>;
    addEventListener: (type: string, listener: (event: any) => void) => void;
};

/** Credential references are the only NFC payload this system recognises. */
const CREDENTIAL_PATTERN = /nfc_[a-f0-9]{64}/;

function decodeCredential(event: any): string | null {
    const records: any[] = event?.message?.records ?? [];

    for (const record of records) {
        try {
            const text = new TextDecoder(record.encoding ?? 'utf-8').decode(record.data);
            const match = CREDENTIAL_PATTERN.exec(text);
            if (match) return match[0];
        } catch {
            // Unreadable record — keep looking at the remaining ones.
        }
    }

    return null;
}

export default function NfcTapPanel({
    disabled,
    onCredential,
}: {
    disabled: boolean;
    /** Called with a credential reference read from the card. */
    onCredential: (credential: string) => void;
}) {
    const { t } = useLocale();
    const [state, setState] = useState<ReaderState>('idle');
    const [manual, setManual] = useState('');
    const abortRef = useRef<AbortController | null>(null);

    const supported = typeof window !== 'undefined' && 'NDEFReader' in window;

    useEffect(() => {
        if (!supported) setState('unsupported');

        return () => abortRef.current?.abort();
    }, [supported]);

    const startScan = useCallback(async () => {
        if (!supported || disabled) return;

        try {
            setState('waiting');
            const controller = new AbortController();
            abortRef.current = controller;

            const reader = new (window as unknown as { NDEFReader: new () => NdefReaderLike }).NDEFReader();
            await reader.scan({ signal: controller.signal });

            reader.addEventListener('reading', (event: any) => {
                const credential = decodeCredential(event);

                if (!credential) {
                    // A tag that carries no credential reference is simply not
                    // one of ours; say so rather than guessing at its contents.
                    setState('error');

                    return;
                }

                setState('detected');
                onCredential(credential);
                setState('verifying');
            });

            reader.addEventListener('readingerror', () => setState('error'));
        } catch {
            setState('error');
        }
    }, [supported, disabled, onCredential]);

    const stateLabel: Record<ReaderState, string> = {
        idle: t('nfc.nfcTapPrompt'),
        waiting: t('nfc.waitingForCard'),
        detected: t('nfc.cardDetected'),
        verifying: t('nfc.authenticatingCard'),
        unsupported: t('nfc.nfcBrowserUnsupported'),
        error: t('nfc.nfcReadError'),
    };

    return (
        <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div className="flex flex-col items-center gap-3 py-6 text-center">
                <span
                    className={`flex h-16 w-16 items-center justify-center rounded-full ${
                        state === 'waiting'
                            ? 'animate-pulse bg-blue-100 text-[color:var(--color-primary)] dark:bg-blue-900/30 dark:text-[color:var(--color-primary)]'
                            : state === 'error' || state === 'unsupported'
                              ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400'
                              : 'bg-gray-100 text-gray-500 dark:bg-slate-800 dark:text-slate-400'
                    }`}
                >
                    <NfcIcon className="h-8 w-8" />
                </span>

                <p className="max-w-sm text-sm text-gray-700 dark:text-slate-200">{stateLabel[state]}</p>

                {supported && state !== 'waiting' && (
                    <button
                        type="button"
                        onClick={startScan}
                        disabled={disabled}
                        className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-50"
                    >
                        {t('nfc.scanMethodNfc')}
                    </button>
                )}
            </div>

            {/*
             * Manual entry keeps the lane usable on hardware without Web NFC:
             * the operator reads the reference from a desk reader utility. It is
             * the same reference-assurance level as a QR scan — the server still
             * applies every card, employee and service rule.
             */}
            <form
                className="mt-2 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4 dark:border-slate-800"
                onSubmit={(event) => {
                    event.preventDefault();
                    const value = CREDENTIAL_PATTERN.exec(manual.trim())?.[0];

                    if (!value) {
                        setState('error');

                        return;
                    }

                    setState('verifying');
                    onCredential(value);
                    setManual('');
                }}
            >
                <label className="flex-1">
                    <span className="mb-1 block text-xs text-gray-500 dark:text-slate-400">
                        {t('nfc.credentialId')}
                    </span>
                    <input
                        value={manual}
                        onChange={(event) => setManual(event.target.value)}
                        placeholder="nfc_…"
                        disabled={disabled}
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"
                    />
                </label>
                <button
                    type="submit"
                    disabled={disabled || manual.trim() === ''}
                    className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                >
                    {t('common.submit')}
                </button>
            </form>
        </div>
    );
}
