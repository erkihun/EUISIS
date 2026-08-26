import PageHeader from '@/Components/PageHeader';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useLocale } from '@/hooks/useLocale';
import type { JSX } from 'react';

/**
 * Create / edit a service provided by a position.
 *
 * Organization is picked first, then the position list loads for that
 * organization. The organization itself is not stored on the record — it is
 * reached through the position — so this field is purely a way to narrow the
 * position picker.
 */

type Organization = { id: string; name_en: string; name_am: string | null };
type PositionOption = { id: string; job_position_code: string | null; title_en: string; title_am: string | null };

export type FormRecord = {
    id: string;
    service_no: string | null;
    name_en: string;
    name_am: string | null;
    description: string | null;
    is_active: boolean;
    is_performance_evaluation_enabled: boolean;
    sort_order: number;
    position: { id: string } | null;
    organization: { id: string } | null;
};

export default function PositionServiceForm({
    organizations,
    record,
    hasFeedback = false,
}: {
    organizations: Organization[];
    record?: FormRecord;
    hasFeedback?: boolean;
}): JSX.Element {
    const { locale, t } = useLocale();
    const am = locale === 'am';
    const isEdit = record !== undefined;

    const [organizationId, setOrganizationId] = useState(record?.organization?.id ?? '');
    const [positions, setPositions] = useState<PositionOption[]>([]);
    const [loadingPositions, setLoadingPositions] = useState(false);

    const { data, setData, post, patch, processing, errors } = useForm({
        organization_id: record?.organization?.id ?? '',
        position_id: record?.position?.id ?? '',
        service_no: record?.service_no ?? '',
        name_en: record?.name_en ?? '',
        name_am: record?.name_am ?? '',
        description: record?.description ?? '',
        is_active: record?.is_active ?? true,
        is_performance_evaluation_enabled: record?.is_performance_evaluation_enabled ?? true,
        sort_order: record?.sort_order ?? 0,
    });

    // Positions depend on the chosen organization, so they are fetched rather
    // than shipped up-front — a city-wide list would be very large.
    useEffect(() => {
        if (organizationId === '') {
            setPositions([]);

            return;
        }

        let cancelled = false;
        setLoadingPositions(true);

        fetch(`${route('position-services.positions')}?organization_id=${organizationId}`, {
            headers: { Accept: 'application/json' },
        })
            .then((response) => response.json())
            .then((payload) => {
                if (!cancelled) {
                    setPositions(payload.positions ?? []);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setPositions([]);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoadingPositions(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [organizationId]);

    function submit(event: React.FormEvent) {
        event.preventDefault();

        if (isEdit) {
            patch(route('position-services.update', record.id));
        } else {
            post(route('position-services.store'));
        }
    }

    const label = (o: { name_en: string; name_am: string | null }) => (am ? (o.name_am ?? o.name_en) : o.name_en);

    return (
        <AuthenticatedLayout>
            <Head title={t('serviceFeedback.positionServices')} />

            <div className="mx-auto max-w-2xl space-y-5">
                <PageHeader
                    title={isEdit ? t('serviceFeedback.editPositionService') : t('serviceFeedback.addPositionService')}
                    description={t('serviceFeedback.positionServicesHint')}
                    backHref={route('position-services.index')}
                />

                <form
                    onSubmit={submit}
                    className="space-y-5 rounded-xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900"
                >
                    <Field label={t('serviceFeedback.filterOrganization')} error={undefined}>
                        <select
                            value={organizationId}
                            onChange={(e) => {
                                setOrganizationId(e.target.value);
                                setData('organization_id', e.target.value);
                                // The old position belongs to the old organization.
                                setData('position_id', '');
                            }}
                            className={selectCls}
                        >
                            <option value="">{t('serviceFeedback.selectOrganization')}</option>
                            {organizations.map((org) => (
                                <option key={org.id} value={org.id}>
                                    {label(org)}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('serviceFeedback.position')} error={errors.position_id}>
                        <select
                            value={data.position_id}
                            onChange={(e) => setData('position_id', e.target.value)}
                            disabled={organizationId === '' || loadingPositions}
                            className={selectCls}
                        >
                            <option value="">
                                {loadingPositions ? t('common.loading') : t('serviceFeedback.selectPosition')}
                            </option>
                            {positions.map((position) => (
                                <option key={position.id} value={position.id}>
                                    {position.job_position_code ? `${position.job_position_code} — ` : ''}
                                    {am ? (position.title_am ?? position.title_en) : position.title_en}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('serviceFeedback.serviceNameEn')} error={errors.name_en}>
                        <input
                            type="text"
                            maxLength={255}
                            value={data.name_en}
                            onChange={(e) => setData('name_en', e.target.value)}
                            placeholder="Employee Record Correction"
                            className={selectCls}
                        />
                    </Field>

                    <Field label={t('serviceFeedback.serviceNameAm')} error={errors.name_am}>
                        <input
                            type="text"
                            maxLength={255}
                            value={data.name_am}
                            onChange={(e) => setData('name_am', e.target.value)}
                            className={selectCls}
                        />
                    </Field>

                    <Field label={t('serviceFeedback.serviceDescription')} error={errors.description}>
                        <textarea
                            rows={2}
                            maxLength={2000}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            className={selectCls}
                        />
                    </Field>

                    <Field
                        label={t('serviceFeedback.serviceIdNo')}
                        error={errors.service_no}
                        hint={hasFeedback ? t('serviceFeedback.serviceTypeLockedAfterFeedback') : undefined}
                    >
                        <input
                            type="text"
                            maxLength={40}
                            value={data.service_no}
                            onChange={(e) => setData('service_no', e.target.value)}
                            placeholder="HR-001"
                            className={selectCls}
                        />
                    </Field>

                    <Field label={t('serviceFeedback.sortOrder')} error={errors.sort_order}>
                        <input
                            type="number"
                            min={0}
                            max={9999}
                            value={data.sort_order}
                            onChange={(e) => setData('sort_order', Number(e.target.value))}
                            className={selectCls}
                        />
                    </Field>

                    <div className="space-y-3 border-t border-gray-100 pt-4 dark:border-slate-800">
                        <Checkbox
                            checked={data.is_active}
                            onChange={(value) => setData('is_active', value)}
                            label={t('common.active')}
                            hint={t('serviceFeedback.activeHint')}
                        />
                        <Checkbox
                            checked={data.is_performance_evaluation_enabled}
                            onChange={(value) => setData('is_performance_evaluation_enabled', value)}
                            label={t('serviceFeedback.usePerformanceEvaluation')}
                            hint={t('serviceFeedback.performanceHint')}
                        />
                    </div>

                    <button
                        type="submit"
                        disabled={processing}
                        className="w-full rounded-lg bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                    >
                        {processing ? t('common.saving') : t('common.save')}
                    </button>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}

const selectCls =
    'mt-1 block w-full rounded-lg border-gray-300 py-2 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100';

function Field({
    label,
    error,
    hint,
    children,
}: {
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}): JSX.Element {
    return (
        <div>
            <label className="block text-sm font-medium text-gray-900 dark:text-slate-100">{label}</label>
            {children}
            {hint && <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">{hint}</p>}
            {error && <p className="mt-1 text-sm text-red-600 dark:text-red-400">{error}</p>}
        </div>
    );
}

function Checkbox({
    checked,
    onChange,
    label,
    hint,
}: {
    checked: boolean;
    onChange: (value: boolean) => void;
    label: string;
    hint?: string;
}): JSX.Element {
    return (
        <label className="flex items-start gap-3">
            <input
                type="checkbox"
                checked={checked}
                onChange={(e) => onChange(e.target.checked)}
                className="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-slate-700"
            />
            <span>
                <span className="block text-sm font-medium text-gray-900 dark:text-slate-100">{label}</span>
                {hint && <span className="block text-xs text-gray-500 dark:text-slate-400">{hint}</span>}
            </span>
        </label>
    );
}
