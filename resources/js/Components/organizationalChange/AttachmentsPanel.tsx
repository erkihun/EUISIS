import { useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import type { FormEvent, JSX } from 'react';
import type { ChangeRequestDetail } from './types';

type Props = {
    request: ChangeRequestDetail;
    canUpload: boolean;
    attachmentTypes: string[];
};

const inputCls =
    'w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

/**
 * Supporting documents.
 *
 * Files are served through an authorised download route, not a public URL, so
 * a decision letter is only readable by someone who may read the request.
 */
export default function AttachmentsPanel({ request, canUpload, attachmentTypes }: Props): JSX.Element {
    const { t } = useLocale();

    const form = useForm<{
        document_type: string;
        reference_no: string;
        document_date: string;
        file: File | null;
    }>({
        document_type: 'supporting_evidence',
        reference_no: '',
        document_date: '',
        file: null,
    });

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post(route('organizational-change-requests.attachments.store', request.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => form.reset(),
        });
    }

    const attachments = request.attachments ?? [];

    return (
        <section className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <header className="border-b border-gray-200 px-4 py-3 dark:border-slate-800">
                <h2 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                    {t('organizationalChangeRequests.attachments.heading')}
                </h2>
            </header>

            {attachments.length === 0 ? (
                <p className="px-4 py-3 text-sm text-gray-500 dark:text-slate-400">
                    {t('organizationalChangeRequests.attachments.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-gray-100 dark:divide-slate-800">
                    {attachments.map(attachment => (
                        <li key={attachment.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium text-gray-900 dark:text-slate-100">
                                    {attachment.original_name}
                                </p>
                                <p className="text-xs text-gray-500 dark:text-slate-400">
                                    {t(`organizationalChangeRequests.attachments.types.${attachment.document_type}`)}
                                    {attachment.reference_no ? ` · ${attachment.reference_no}` : ''}
                                    {attachment.document_date ? ' · ' : ''}
                                    {attachment.document_date ? <LocalizedDateDisplay value={attachment.document_date} /> : null}
                                    {attachment.uploader ? ` · ${attachment.uploader.name}` : ''}
                                </p>
                            </div>
                            <a
                                href={route('organizational-change-requests.attachments.download', [request.id, attachment.id])}
                                className="shrink-0 text-xs font-semibold text-[color:var(--color-primary)] hover:underline"
                            >
                                {t('organizationalChangeRequests.actions.download')}
                            </a>
                        </li>
                    ))}
                </ul>
            )}

            {canUpload && (
                <form className="border-t border-gray-100 p-4 dark:border-slate-800" onSubmit={submit}>
                    <div className="grid gap-3 md:grid-cols-4">
                        <label className="block space-y-1.5 text-xs font-medium text-gray-600 dark:text-slate-300">
                            <span>{t('organizationalChangeRequests.fields.documentType')}</span>
                            <select
                                className={inputCls}
                                value={form.data.document_type}
                                onChange={event => form.setData('document_type', event.target.value)}
                            >
                                {attachmentTypes.map(type => (
                                    <option key={type} value={type}>
                                        {t(`organizationalChangeRequests.attachments.types.${type}`)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block space-y-1.5 text-xs font-medium text-gray-600 dark:text-slate-300">
                            <span>{t('organizationalChangeRequests.fields.referenceNo')}</span>
                            <input
                                type="text"
                                className={inputCls}
                                value={form.data.reference_no}
                                onChange={event => form.setData('reference_no', event.target.value)}
                            />
                        </label>
                        <label className="block space-y-1.5 text-xs font-medium text-gray-600 dark:text-slate-300">
                            <span>{t('organizationalChangeRequests.fields.documentDate')}</span>
                            <input
                                type="date"
                                className={inputCls}
                                value={form.data.document_date}
                                onChange={event => form.setData('document_date', event.target.value)}
                            />
                        </label>
                        <label className="block space-y-1.5 text-xs font-medium text-gray-600 dark:text-slate-300">
                            <span>{t('organizationalChangeRequests.fields.file')}</span>
                            <input
                                type="file"
                                className={inputCls}
                                onChange={event => form.setData('file', event.target.files?.[0] ?? null)}
                            />
                        </label>
                    </div>
                    {form.errors.file && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{form.errors.file}</p>}
                    <div className="mt-3 flex justify-end">
                        <button
                            type="submit"
                            disabled={form.processing || !form.data.file}
                            className="rounded-lg bg-[color:var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-50"
                        >
                            {t('organizationalChangeRequests.actions.uploadDocument')}
                        </button>
                    </div>
                </form>
            )}
        </section>
    );
}
