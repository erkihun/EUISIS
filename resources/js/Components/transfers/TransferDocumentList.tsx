import { DownloadIcon } from '@/Components/Icons';
import { useLocale } from '@/hooks/useLocale';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';
import { buttonClassName } from '@/Components/ui';

interface Document {
    id: string;
    document_type: string;
    original_name: string;
    file_size?: number | null;
}

interface Props {
    documents: Document[];
    /** Where one document downloads from: the staff route or the applicant's own. */
    hrefFor: (document: Document) => string;
}

/**
 * The evidence an applicant attached, one row per required document. Plain
 * links: the browser downloads through an authorized route, nothing is
 * previewed inline.
 */
export default function TransferDocumentList({ documents, hrefFor }: Props) {
    const { t } = useLocale();
    const { number } = useDisplayFormat();

    if (documents.length === 0) {
        return <p className="text-sm text-(--app-muted-foreground)">{t('transfers.noDocumentsAttached')}</p>;
    }

    return (
        <ul className="divide-y divide-(--app-border)">
            {documents.map((document) => (
                <li key={document.id} className="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                    <div className="min-w-0">
                        <p className="text-sm font-medium wrap-break-word text-(--app-foreground)">{document.document_type}</p>
                        <p className="truncate text-xs text-(--app-muted-foreground)">
                            {document.original_name}
                            {document.file_size ? ` · ${number(Math.max(1, Math.round(document.file_size / 1024)))} KB` : ''}
                        </p>
                    </div>
                    <a
                        href={hrefFor(document)}
                        className={buttonClassName({ variant: 'outline', size: 'sm' })}
                        aria-label={`${t('transfers.download')}: ${document.document_type}`}
                    >
                        <DownloadIcon className="size-4" aria-hidden="true" />
                        <span className="hidden sm:inline">{t('transfers.download')}</span>
                    </a>
                </li>
            ))}
        </ul>
    );
}
