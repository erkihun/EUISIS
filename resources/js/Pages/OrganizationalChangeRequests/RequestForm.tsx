import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import RequestTypeFields from '@/Components/organizationalChange/RequestTypeFields';
import LocalizedDatePicker from '@/Components/Calendar/LocalizedDatePicker';
import { Head, Link, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { InfoIcon } from '@/Components/Icons';
import type { FormEvent, JSX } from 'react';
import { useState } from 'react';
import type { ChangeRequestDetail, FormOptions } from '@/Components/organizationalChange/types';

type Props = {
    request?: ChangeRequestDetail;
    options: FormOptions;
    allowedTypes: string[];
};

const inputCls =
    'w-full min-w-0 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-[color:var(--color-primary)] focus:outline-none focus:ring-1 focus:ring-[color:var(--color-primary)] dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';
const labelCls = 'block space-y-1.5 text-xs font-medium text-gray-600 dark:text-slate-300';

/**
 * Create or edit a change request.
 *
 * The same form serves both, because a correction is an edit of the same
 * proposal rather than a new request. On edit the organization and request
 * type are locked: changing either would make the review history refer to
 * something that no longer exists.
 */
export default function RequestForm({ request, options, allowedTypes }: Props): JSX.Element {
    const { t, locale } = useLocale();
    const am = locale === 'am';
    const isEdit = Boolean(request);

    const existingItem = request?.items?.[0];

    const [payload, setPayload] = useState<Record<string, unknown>>(() => {
        if (!existingItem) {
            return {};
        }
        return { ...(existingItem.proposed_data ?? {}), entity_id: existingItem.entity_id ?? undefined };
    });

    /*
     * The type-specific payload is deliberately NOT part of the form state:
     * its shape changes with the request type, and keeping it out lets the
     * header fields stay strongly typed. It is merged in by transform() on
     * submit instead.
     */
    const form = useForm({
        organization_id: request?.organization?.id ?? '',
        request_type: String(request?.request_type ?? ''),
        reason: request?.reason ?? '',
        priority: request?.priority ?? 'normal',
        requested_effective_date: request?.requested_effective_date ?? '',
    });

    function setPayloadValue(key: string, value: unknown) {
        setPayload(current => ({ ...current, [key]: value }));
    }

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();

        // The payload is assembled here rather than tracked in useForm so the
        // type-specific fields can change shape without stale keys tagging along.
        form.transform(data => ({ ...data, payload }));

        if (isEdit && request) {
            form.patch(route('organizational-change-requests.update', request.id));
            return;
        }

        form.post(route('organizational-change-requests.store'));
    }

    const errors = form.errors as unknown as Record<string, string>;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={isEdit
                        ? `${t('organizationalChangeRequests.title')} — ${request?.request_no}`
                        : t('organizationalChangeRequests.actions.create')}
                    description={t('organizationalChangeRequests.description')}
                />
            }
        >
            <Head title={t('organizationalChangeRequests.actions.create')} />

            <form className="space-y-4" onSubmit={submit}>
                <section className="flex items-start gap-2.5 rounded-panel border border-blue-200 bg-blue-50/50 px-4 py-3 dark:border-blue-900/40 dark:bg-blue-950/20">
                    <InfoIcon className="mt-0.5 h-4 w-4 shrink-0 text-blue-700 dark:text-blue-400" aria-hidden="true" />
                    <p className="text-sm text-blue-900 dark:text-blue-200">
                        {t('organizationalChangeRequests.notice.requesterCannotEdit')}
                    </p>
                </section>

                <section className="rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label className={labelCls}>
                                <span>{t('organizationalChangeRequests.fields.organization')}</span>
                                <select
                                    className={inputCls}
                                    value={form.data.organization_id}
                                    disabled={isEdit}
                                    onChange={event => form.setData('organization_id', event.target.value)}
                                >
                                    <option value="">{t('organizationalChangeRequests.form.selectOrganization')}</option>
                                    {options.organizations.map(organization => (
                                        <option key={organization.id} value={organization.id}>
                                            {am ? organization.name_am || organization.name_en : organization.name_en}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {errors.organization_id && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.organization_id}</p>}
                        </div>

                        <div>
                            <label className={labelCls}>
                                <span>{t('organizationalChangeRequests.fields.requestType')}</span>
                                <select
                                    className={inputCls}
                                    value={form.data.request_type}
                                    disabled={isEdit}
                                    onChange={event => {
                                        form.setData('request_type', event.target.value);
                                        // A different type means a different payload shape.
                                        setPayload({});
                                    }}
                                >
                                    <option value="">{t('organizationalChangeRequests.form.selectType')}</option>
                                    {allowedTypes.map(type => (
                                        <option key={type} value={type}>
                                            {t(`organizationalChangeRequests.types.${type}`)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {errors.request_type && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.request_type}</p>}
                        </div>

                        <div>
                            <label className={labelCls}>
                                <span>{t('organizationalChangeRequests.fields.priority')}</span>
                                <select
                                    className={inputCls}
                                    value={form.data.priority}
                                    onChange={event => form.setData('priority', event.target.value)}
                                >
                                    {['low', 'normal', 'high', 'urgent'].map(priority => (
                                        <option key={priority} value={priority}>
                                            {t(`organizationalChangeRequests.priorities.${priority}`)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        <div className="md:col-span-2 xl:col-span-1">
                            <div className={labelCls}>
                                <label htmlFor="ocr-effective">{t('organizationalChangeRequests.fields.effectiveDate')}</label>
                                <LocalizedDatePicker
                                    id="ocr-effective"
                                    className={inputCls}
                                    value={form.data.requested_effective_date}
                                    onChange={value => form.setData('requested_effective_date', value)}
                                />
                            </div>
                        </div>
                    </div>
                </section>

                {form.data.request_type && (
                    <section className="rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <h2 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                            {t('organizationalChangeRequests.compare.heading')}
                        </h2>
                        <RequestTypeFields
                            requestType={form.data.request_type}
                            organizationId={form.data.organization_id}
                            payload={payload}
                            onChange={setPayloadValue}
                            options={options}
                            errors={errors}
                        />
                    </section>
                )}

                <section className="rounded-panel border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <label className={labelCls}>
                        <span>{t('organizationalChangeRequests.fields.reason')}</span>
                        <textarea
                            rows={4}
                            className={inputCls}
                            value={form.data.reason}
                            onChange={event => form.setData('reason', event.target.value)}
                        />
                    </label>
                    {errors.reason && <p className="mt-1 text-xs text-red-600 dark:text-red-400">{errors.reason}</p>}
                </section>

                <div className="flex flex-wrap items-center justify-end gap-2">
                    <Link
                        href={route('organizational-change-requests.index')}
                        className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                    >
                        {t('common.cancel')}
                    </Link>
                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-[color:var(--color-primary)] px-5 py-2 text-sm font-semibold text-white hover:bg-[color:var(--color-primary-hover)] disabled:opacity-50"
                    >
                        {t('organizationalChangeRequests.actions.saveDraft')}
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
