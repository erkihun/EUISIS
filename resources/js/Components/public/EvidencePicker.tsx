import { ChangeEvent, useRef, useState } from 'react';
import { buttonClassName } from '@euisis/ui';
import { X } from '@/Components/Icons';
import { MicIcon, PhotoIcon, ScrollText, VideoIcon, type NavIcon } from '@/Components/NavIcons';
import { useLocale } from '@/hooks/useLocale';
import type { EvidenceLimits } from '@/types/publicSubmissions';

export type EvidenceKind = keyof EvidenceLimits['max_mb'];

/** The picker for each kind. `accept` only steers the phone's chooser; the server judges the content. */
const KINDS: Array<{ kind: EvidenceKind; label: string; accept: string; Icon: NavIcon }> = [
    { kind: 'photo', label: 'serviceFeedback.evidencePhoto', accept: 'image/*', Icon: PhotoIcon },
    { kind: 'video', label: 'serviceFeedback.evidenceVideo', accept: 'video/*', Icon: VideoIcon },
    { kind: 'audio', label: 'serviceFeedback.evidenceAudio', accept: 'audio/*', Icon: MicIcon },
    { kind: 'document', label: 'serviceFeedback.evidenceDocument', accept: 'application/pdf,.pdf', Icon: ScrollText },
];

function kindOf(file: File): EvidenceKind | null {
    if (file.type.startsWith('image/')) return 'photo';
    if (file.type.startsWith('video/')) return 'video';
    if (file.type.startsWith('audio/')) return 'audio';
    if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) return 'document';

    return null;
}

function formatSize(bytes: number): string {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Optional evidence for a Tip-off or a Complaint: large Photo, Video and Audio
 * buttons (and PDF), each opening the phone's own chooser, then the chosen
 * files listed with a remove control each.
 *
 * Files the browser can already tell are too big, of an unknown kind, or past
 * the count are turned away here with a message, so a visitor does not wait
 * for a long upload only to be refused. The server checks every file again by
 * its content (PublicEvidencePolicy) and its answer is final.
 */
export default function EvidencePicker({ id, files, onChange, limits, error, describedBy, disabled = false }: {
    id: string;
    files: File[];
    onChange: (files: File[]) => void;
    limits: EvidenceLimits;
    error?: string;
    describedBy?: string;
    disabled?: boolean;
}) {
    const { t } = useLocale();
    const inputs = useRef<Partial<Record<EvidenceKind, HTMLInputElement | null>>>({});
    const [notice, setNotice] = useState<string | null>(null);
    const full = files.length >= limits.max_files;
    const kindLabel = (kind: EvidenceKind) => t(KINDS.find((k) => k.kind === kind)?.label ?? '');

    function add(event: ChangeEvent<HTMLInputElement>) {
        const picked = Array.from(event.target.files ?? []);
        const next = [...files];
        let refused: string | null = null;

        for (const file of picked) {
            const kind = kindOf(file);
            if (kind === null) {
                refused = t('serviceFeedback.attachmentHint').replace('{files}', String(limits.max_files)).replace('{total}', String(limits.max_total_mb));
                continue;
            }
            if (file.size > limits.max_mb[kind] * 1024 * 1024) {
                refused = t('serviceFeedback.evidenceLimits')
                    .replace('{photo}', String(limits.max_mb.photo)).replace('{video}', String(limits.max_mb.video))
                    .replace('{audio}', String(limits.max_mb.audio)).replace('{document}', String(limits.max_mb.document));
                continue;
            }
            if (next.length >= limits.max_files) {
                refused = t('serviceFeedback.evidenceTooMany').replace('{max}', String(limits.max_files));
                break;
            }
            next.push(file);
        }

        setNotice(refused);
        onChange(next);
        // Reset so choosing the same file again after removing it still fires.
        event.target.value = '';
    }

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {KINDS.map(({ kind, label, accept, Icon }) => (
                    <div key={kind}>
                        <button
                            type="button"
                            disabled={disabled || full}
                            aria-describedby={describedBy}
                            onClick={() => inputs.current[kind]?.click()}
                            className="flex min-h-[72px] w-full flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-[color:var(--app-border-strong)] bg-[color:var(--app-surface)] px-2 py-3 text-sm font-semibold text-[color:var(--app-foreground)] transition-colors hover:bg-[color:var(--app-surface-muted)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[color:var(--color-primary)] disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <Icon className="h-6 w-6 text-[color:var(--color-primary)]" aria-hidden="true" />
                            <span>{t('serviceFeedback.evidenceAdd').replace('{kind}', t(label))}</span>
                        </button>
                        <input
                            ref={(node) => { inputs.current[kind] = node; }}
                            id={kind === 'photo' ? id : `${id}-${kind}`}
                            type="file"
                            multiple
                            accept={accept}
                            tabIndex={-1}
                            aria-hidden="true"
                            className="hidden"
                            onChange={add}
                        />
                    </div>
                ))}
            </div>

            <p className="text-xs text-[color:var(--app-muted-foreground)]" aria-live="polite">
                {t('serviceFeedback.evidenceSelected').replace('{count}', String(files.length)).replace('{max}', String(limits.max_files))}
            </p>
            {notice && <p role="status" className="text-sm text-amber-700 dark:text-amber-300">{notice}</p>}
            {error && <p role="alert" className="text-sm text-red-600 dark:text-red-400">{error}</p>}

            {files.length > 0 && (
                <ul className="divide-y divide-[color:var(--app-border)] rounded-xl border border-[color:var(--app-border)]">
                    {files.map((file, index) => {
                        const kind = kindOf(file);
                        const Icon = KINDS.find((k) => k.kind === kind)?.Icon ?? ScrollText;

                        return (
                            <li key={`${file.name}-${file.size}-${index}`} className="flex items-center gap-3 px-3 py-2 text-sm text-[color:var(--app-foreground)]">
                                <Icon className="h-5 w-5 shrink-0 text-[color:var(--app-muted-foreground)]" aria-hidden="true" />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate">{file.name}</span>
                                    <span className="block text-xs text-[color:var(--app-muted-foreground)]">{kind ? kindLabel(kind) : ''} · {formatSize(file.size)}</span>
                                </span>
                                <button
                                    type="button"
                                    onClick={() => { setNotice(null); onChange(files.filter((_, i) => i !== index)); }}
                                    className={buttonClassName({ variant: 'ghost', size: 'icon', className: 'h-11 w-11 shrink-0' })}
                                    aria-label={t('serviceFeedback.evidenceRemove').replace('{name}', file.name)}
                                >
                                    <X className="h-5 w-5" aria-hidden="true" />
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
