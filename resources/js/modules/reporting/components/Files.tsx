import { cx } from '@/Components/ui';
import { badge, formatBytes, mutedCls, secondaryBtn, useReporting } from '@/modules/reporting/lib/helpers';
import type { ArtifactRow, AttachmentRow } from '@/modules/reporting/types';
import { router } from '@inertiajs/react';
import { useEffect, type JSX } from 'react';

/** Files kept with a version. Downloads go through an authorized, audited route; there is no public address. */
export function AttachmentList({ files, onRemove, empty }: { files: AttachmentRow[]; onRemove?: (file: AttachmentRow) => void; empty?: string }): JSX.Element {
    const { rt } = useReporting();
    if (files.length === 0) {
        return <p className={mutedCls}>{empty ?? rt('files.none')}</p>;
    }

    return (
        <ul className="divide-y divide-(--app-border)">
            {files.map((file) => (
                <li key={file.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span className="min-w-0">
                        <a href={file.url} className="font-medium text-primary underline-offset-2 hover:underline">{file.name}</a>
                        <span className={cx(mutedCls, 'ms-2 text-xs')}>{rt(`files.kind.${file.kind}`)} · {formatBytes(file.size)}</span>
                        {file.scan_status === 'not_scanned' && <span className={cx(badge('neutral'), 'ms-2')} title={rt('files.notScannedHelp')}>{rt('files.notScanned')}</span>}
                    </span>
                    {onRemove && !file.submitted && <button type="button" className={secondaryBtn} onClick={() => onRemove(file)}>{rt('files.remove')}</button>}
                </li>
            ))}
        </ul>
    );
}

/**
 * Generated documents and the state of their generation. The submission
 * exists whatever the state here: a file still being prepared, or one that
 * failed, never means the report was not submitted. While a file is queued or
 * processing the list refreshes itself.
 */
export function ArtifactList({ artifacts, reloadOnly }: { artifacts: ArtifactRow[]; reloadOnly: string[] }): JSX.Element | null {
    const { rt, moment } = useReporting();
    const pending = artifacts.some((a) => a.status === 'queued' || a.status === 'processing');

    useEffect(() => {
        if (!pending) return;
        const timer = setInterval(() => router.reload({ only: reloadOnly }), 5000);

        return () => clearInterval(timer);
    }, [pending, reloadOnly]);

    if (artifacts.length === 0) return null;

    return (
        <ul className="divide-y divide-(--app-border)" aria-live="polite">
            {artifacts.map((artifact) => (
                <li key={artifact.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <span>
                        <span className="font-medium">{rt(`files.generated.${artifact.label}`)}</span>
                        <span className={cx(mutedCls, 'ms-2 text-xs')}>{artifact.format === 'pdf' ? rt('files.pdfPreview') : rt('files.word')}{artifact.size ? ` · ${formatBytes(artifact.size)}` : ''}</span>
                    </span>
                    {artifact.status === 'completed' && artifact.url
                        ? <a href={artifact.url} className={secondaryBtn}>{artifact.format === 'pdf' ? rt('files.open') : rt('files.download')}</a>
                        : <span className={badge(artifact.status === 'failed' ? 'danger' : 'info')}>{rt(`files.state.${artifact.status}`)}</span>}
                    {artifact.status === 'completed' && artifact.completed_at && <span className="sr-only">{moment(artifact.completed_at)}</span>}
                    {artifact.status === 'failed' && <p className={cx(mutedCls, 'w-full text-xs')}>{rt('files.failedHelp')}</p>}
                </li>
            ))}
        </ul>
    );
}
