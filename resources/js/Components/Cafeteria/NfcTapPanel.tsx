import { useCallback, useEffect, useRef, useState } from 'react';
import { NfcIcon } from '@/Components/Icons';
import { Button, Input, cx } from '@euisis/ui';
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
        <div className="rounded-[var(--radius-card)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5 text-[color:var(--app-foreground)]">
            <div className="flex flex-col items-center gap-3 py-6 text-center">
                <span
                    className={cx('flex h-16 w-16 items-center justify-center rounded-full',
                        state === 'waiting'
                            ? 'bg-[color:var(--color-primary)]/10 text-[color:var(--color-primary)] motion-safe:animate-pulse'
                            : state === 'error' || state === 'unsupported'
                              ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300'
                              : 'bg-[color:var(--app-surface-muted)] text-[color:var(--app-muted-foreground)]')}
                >
                    <NfcIcon className="h-8 w-8" />
                </span>

                <p role="status" className="max-w-sm text-sm leading-6">{stateLabel[state]}</p>

                {supported && state !== 'waiting' && (
                    <Button variant="primary" onClick={startScan} disabled={disabled} icon={<NfcIcon className="h-4 w-4" />}>
                        {t('nfc.scanMethodNfc')}
                    </Button>
                )}
            </div>

            {/*
             * Manual entry keeps the lane usable on hardware without Web NFC:
             * the operator reads the reference from a desk reader utility. It is
             * the same reference-assurance level as a QR scan — the server still
             * applies every card, employee and service rule.
             */}
            <form
                className="mt-2 flex flex-wrap items-end gap-2 border-t border-[color:var(--app-border)] pt-4"
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
                <label className="min-w-0 flex-1 basis-48">
                    <span className="mb-1.5 block text-xs font-medium text-[color:var(--app-muted-foreground)]">
                        {t('nfc.credentialId')}
                    </span>
                    <Input
                        value={manual}
                        onChange={(event) => setManual(event.target.value)}
                        placeholder="nfc_…"
                        disabled={disabled}
                        autoComplete="off"
                        spellCheck={false}
                        className="font-mono"
                    />
                </label>
                <Button type="submit" variant="outline" disabled={disabled || manual.trim() === ''}>
                    {t('common.submit')}
                </Button>
            </form>
        </div>
    );
}
