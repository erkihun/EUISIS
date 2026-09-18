import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import StatusBadge from '@/Components/StatusBadge';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Head, Link } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useCan } from '@/hooks/useCan';
import EmployeeQrCards, { type QrCodesProp } from '@/Components/employees/EmployeeQrCards';
import { localizedName } from '@/utils/localizedName';
import { useDisplayFormat } from '@/hooks/useDisplayFormat';

type LocalizedOrganization = { id?: string; name_en: string; name_am?: string | null };
type LocalizedPosition = { title_en: string; title_am?: string | null };

type EmployeeDetail = {
    id: string;
    employee_number: string;
    first_name: string;
    middle_name?: string | null;
    last_name: string;
    full_name: string;
    national_id?: string | null;
    phone?: string | null;
    email?: string | null;
    status: string;
    date_of_birth?: string | null;
    gender?: string | null;
    address?: string | null;
    nationality?: string | null;
    employment_type_label?: string | null;
    emergency_contact_name?: string | null;
    emergency_contact_phone?: string | null;
    photo_url?: string | null;
    data_quality_score?: number | null;
    current_assignment?: {
        organization?: LocalizedOrganization | null;
        position?: LocalizedPosition | null;
        effective_from?: string | null;
    } | null;
    assignments?: Array<{
        id: string;
        assignment_status: string;
        effective_from: string | null;
        effective_to: string | null;
        reason?: string | null;
        organization?: LocalizedOrganization | null;
        position?: LocalizedPosition | null;
    }>;
    duplicate_flags?: Array<{
        id: string;
        risk_score: number;
        matched_fields: string[];
        matched_employee?: { employee_number: string; full_name: string } | null;
    }>;
    documents?: Array<{
        id: string;
        document_type: string;
        storage_disk: string;
        is_private: boolean;
        created_at: string | null;
    }>;
    transfers?: Array<{
        id: string;
        status: string;
        effective_date: string | null;
        from_organization?: LocalizedOrganization | null;
        to_organization?: LocalizedOrganization | null;
    }>;
};

function Field({ label, value }: { label: string; value?: string | number | null }) {
    return (
        <div>
            <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">
                {label}
            </dt>
            <dd className="mt-1 text-sm text-gray-900 dark:text-slate-100">
                {value ?? <span className="text-gray-400 dark:text-slate-600">—</span>}
            </dd>
        </div>
    );
}

function formatNationalId(raw?: string | null): string {
    if (!raw) return '—';
    return raw.replace(/(.{4})/g, '$1 ').trim();
}

export default function EmployeesShow({
    employee,
    qrCodes,
}: {
    employee: EmployeeDetail;
    qrCodes?: QrCodesProp;
}) {
    const { t, locale } = useLocale();
    const { can } = useCan();
    const { organizationName: orgDisplayName, employeeName } = useDisplayFormat();

    const statusLabel = (status: string): string => {
        const employeeKey = `employees.${status}`;
        const employeeLabel = t(employeeKey);
        if (employeeLabel !== employeeKey) return employeeLabel;

        const commonKey = `common.${status}`;
        const commonLabel = t(commonKey);

        return commonLabel !== commonKey ? commonLabel : status;
    };

    const organizationName = (organization?: LocalizedOrganization | null): string | undefined =>
        organization ? orgDisplayName(organization.name_en, organization.name_am) : undefined;

    const positionName = (position?: LocalizedPosition | null): string | undefined =>
        position ? localizedName(position.title_en, position.title_am, locale) : undefined;

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    backHref={route('employees.index')}
                    title={employeeName(employee)}
                    description={employee.employee_number}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {/*
                              * Feedback QR is shown only to users who may manage
                              * it. The page it links to re-checks the permission
                              * AND the employee's organization scope server-side.
                              */}
                            {can('service_feedback.settings.manage') && (
                                <Link
                                    href={route('employees.feedback-qr.show', employee.id)}
                                    className="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
                                >
                                    {t('serviceFeedback.employeeFeedbackQr')}
                                </Link>
                            )}
                            <Link
                                href={route('employees.edit', employee.id)}
                                className="rounded-lg bg-[color:var(--color-primary)] px-3 py-1.5 text-sm font-medium text-white hover:bg-[color:var(--color-primary-hover)]"
                            >
                                {t('employees.editEmployee')}
                            </Link>
                        </div>
                    }
                />
            }
        >
            <Head title={employee.full_name} />

            <div className="mx-auto max-w-7xl space-y-5">

                {/* ── Profile card ─────────────────────────────────────── */}
                <div className="overflow-hidden rounded-panel border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                    <div className="px-5 py-5 sm:px-6">
                        {/* Identity row */}
                        <div className="mb-6 flex flex-col gap-5 lg:flex-row lg:items-center">
                            <div className="flex-shrink-0">
                                {employee.photo_url ? (
                                    <img
                                        src={employee.photo_url}
                                        alt={employee.full_name}
                                        className="h-24 w-20 rounded-card border border-slate-200 object-cover dark:border-slate-700"
                                    />
                                ) : (
                                    <div className="flex h-24 w-20 items-center justify-center rounded-card border border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-800">
                                        <svg className="h-9 w-9 text-slate-400 dark:text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                        </svg>
                                    </div>
                                )}
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h2 className="truncate text-xl font-semibold text-gray-900 dark:text-slate-100">
                                        {employeeName(employee)}
                                    </h2>
                                    <StatusBadge status={employee.status} label={statusLabel(employee.status)} />
                                </div>
                                <p className="mt-1 text-sm text-gray-500 dark:text-slate-400">
                                    {positionName(employee.current_assignment?.position) ?? t('employees.noPosition')}
                                    {organizationName(employee.current_assignment?.organization) && (
                                        <> · {organizationName(employee.current_assignment?.organization)}</>
                                    )}
                                </p>
                            </div>
                            {/*
                             * Record counts, not KPIs — they are here because a
                             * clerk opening a file wants to know at a glance
                             * whether there is history behind it.
                             */}
                            <dl className="grid w-full grid-cols-3 overflow-hidden rounded-card border border-slate-200 lg:w-auto dark:border-slate-700">
                                <div className="px-4 py-2.5 text-center">
                                    <dd className="text-base font-semibold tabular-nums text-slate-900 dark:text-slate-100">{employee.assignments?.length ?? 0}</dd>
                                    <dt className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{t('employees.assignmentHistory')}</dt>
                                </div>
                                <div className="border-x border-slate-200 px-4 py-2.5 text-center dark:border-slate-700">
                                    <dd className="text-base font-semibold tabular-nums text-slate-900 dark:text-slate-100">{employee.transfers?.length ?? 0}</dd>
                                    <dt className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{t('employees.transferHistory')}</dt>
                                </div>
                                <div className="px-4 py-2.5 text-center">
                                    <dd className="text-base font-semibold tabular-nums text-slate-900 dark:text-slate-100">{employee.data_quality_score ?? 0}%</dd>
                                    <dt className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{t('employees.dataQualityScore')}</dt>
                                </div>
                            </dl>
                        </div>

                        {/* Identity details grid */}
                        <dl className="grid gap-x-8 gap-y-5 border-t border-slate-100 pt-6 sm:grid-cols-2 lg:grid-cols-4 dark:border-slate-800">
                            <Field label={t('employees.employeeNumber')} value={employee.employee_number} />
                            <Field label={t('employees.nationalId')} value={formatNationalId(employee.national_id)} />
                            <Field label={t('employees.employmentStatus')} value={statusLabel(employee.status)} />
                            <Field label={t('employees.firstName')} value={employee.first_name} />
                            <Field label={t('employees.middleName')} value={employee.middle_name} />
                            <Field label={t('employees.lastName')} value={employee.last_name} />
                            <Field label={t('employees.phone')} value={employee.phone} />
                            <Field label={t('employees.email')} value={employee.email} />
                            <Field
                                label={t('employees.gender')}
                                value={employee.gender ? t(`employees.${employee.gender}`) : null}
                            />
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('employees.dateOfBirth')}</dt>
                                <dd className="mt-1 text-sm text-gray-900 dark:text-slate-100"><LocalizedDateDisplay value={employee.date_of_birth} /></dd>
                            </div>
                            <Field label={t('employees.dataQualityScore')} value={employee.data_quality_score ?? 0} />
                        </dl>

                        {/*
                          * Additional employee information. Rendered only on this
                          * authenticated, organization-scoped detail page — the
                          * public ID Checker builds its own field list and never
                          * includes address or emergency contact.
                          */}
                        <div className="mt-6 border-t border-slate-100 pt-6 dark:border-slate-800">
                            <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                                {t('employees.sectionAdditional')}
                            </h3>
                            <dl className="grid gap-x-8 gap-y-5 sm:grid-cols-2 lg:grid-cols-4">
                                <Field label={t('employees.address')} value={employee.address} />
                                <Field label={t('employees.nationality')} value={employee.nationality} />
                                <Field label={t('employees.employeeStatus')} value={employee.employment_type_label} />
                                <Field label={t('employees.emergencyContactName')} value={employee.emergency_contact_name} />
                                <Field label={t('employees.emergencyContactPhone')} value={employee.emergency_contact_phone} />
                            </dl>
                        </div>
                    </div>
                </div>

                {/* ── Current assignment ───────────────────────────────── */}
                <div className="rounded-panel border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                    <h3 className="mb-4 text-sm font-semibold text-gray-900 dark:text-slate-100">
                        {t('employees.currentOrganization')}
                    </h3>
                    {employee.current_assignment ? (
                        <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                            <Field
                                label={t('organizations.title')}
                                value={organizationName(employee.current_assignment.organization)}
                            />
                            <Field
                                label={t('employees.columnPosition')}
                                value={positionName(employee.current_assignment.position)}
                            />
                            <div>
                                <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{t('common.effectiveFrom')}</dt>
                                <dd className="mt-1 text-sm text-gray-900 dark:text-slate-100"><LocalizedDateDisplay value={employee.current_assignment.effective_from} /></dd>
                            </div>
                        </dl>
                    ) : (
                        <p className="text-sm text-gray-400 dark:text-slate-500">{t('common.unassigned')}</p>
                    )}
                </div>

                {/* ── Employee QR codes ────────────────────────────────── */}
                <EmployeeQrCards employeeId={employee.id} qrCodes={qrCodes} />

                <div className="grid items-start gap-5 lg:grid-cols-2">
                    {/* ── Assignment history ───────────────────────────── */}
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-4">
                            {t('employees.assignmentHistory')}
                        </h3>
                        <div className="space-y-3 text-sm">
                            {(employee.assignments ?? []).length === 0 ? (
                                <p className="text-gray-400 dark:text-slate-500">—</p>
                            ) : (employee.assignments ?? []).map((a) => (
                                <div
                                    key={a.id}
                                    className="rounded-card border border-gray-100 bg-gray-50 p-4 dark:border-slate-800 dark:bg-slate-950"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="font-medium text-gray-900 dark:text-slate-100">
                                            {organizationName(a.organization) ?? t('employees.unknownOrganization')}
                                        </div>
                                        <StatusBadge status={a.assignment_status} label={statusLabel(a.assignment_status)} />
                                    </div>
                                    <div className="mt-1 text-gray-500 dark:text-slate-400">
                                        {positionName(a.position) ?? t('employees.noPosition')}
                                    </div>
                                    <div className="mt-1 text-xs text-gray-400 dark:text-slate-500">
                                        <LocalizedDateDisplay value={a.effective_from} fallback="?" />
                                        {' → '}
                                        {a.effective_to ? <LocalizedDateDisplay value={a.effective_to} /> : t('common.present')}
                                    </div>
                                    {a.reason && (
                                        <div className="mt-2 text-xs text-gray-400 dark:text-slate-500 italic">
                                            {a.reason}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>

                    {/* ── Transfers ────────────────────────────────────── */}
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="flex items-center justify-between gap-3 mb-4">
                            <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">
                                {t('employees.transferHistory')}
                            </h3>
                            <Link
                                href={route('transfers.dashboard')}
                                className="rounded-lg bg-emerald-600 px-3 py-1 text-xs font-medium text-white hover:bg-emerald-700"
                            >
                                {t('nav.transferManagement')}
                            </Link>
                        </div>
                        <div className="space-y-3 text-sm">
                            {(employee.transfers ?? []).length === 0 ? (
                                <p className="text-gray-400 dark:text-slate-500">{t('employees.noTransfersFound')}</p>
                            ) : (employee.transfers ?? []).map((tr) => (
                                <Link
                                    key={tr.id}
                                    href={route('transfers.dashboard')}
                                    className="flex items-center justify-between gap-3 rounded-card border border-gray-100 bg-gray-50 p-4 transition hover:border-blue-200 hover:bg-blue-50/50 dark:border-slate-800 dark:bg-slate-950 dark:hover:border-[color:var(--color-primary)]/30"
                                >
                                    <div className="min-w-0">
                                        <div className="font-medium text-gray-900 dark:text-slate-100 truncate">
                                            {organizationName(tr.from_organization) ?? t('employees.unknownOrganization')}
                                            {' → '}
                                            {organizationName(tr.to_organization) ?? t('employees.unknownOrganization')}
                                        </div>
                                        <div className="mt-0.5 text-xs text-gray-400 dark:text-slate-500">
                                            {tr.effective_date
                                                ? <LocalizedDateDisplay value={tr.effective_date} />
                                                : '—'}
                                        </div>
                                    </div>
                                    <StatusBadge status={tr.status} label={statusLabel(tr.status)} />
                                </Link>
                            ))}
                        </div>
                    </div>
                </div>

                <div className="grid items-start gap-5 lg:grid-cols-2">
                    {/* ── Duplicate warnings ───────────────────────────── */}
                    {(employee.duplicate_flags?.length ?? 0) > 0 && (
                        <div className="rounded-panel border border-amber-200 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/5">
                            <h3 className="text-sm font-semibold text-amber-700 dark:text-amber-400 mb-4">
                                {t('employees.duplicateWarnings')} ({employee.duplicate_flags?.length})
                            </h3>
                            <div className="space-y-3 text-sm">
                                {(employee.duplicate_flags ?? []).map((flag) => (
                                    <div
                                        key={flag.id}
                                        className="rounded-card border border-amber-200 bg-white p-4 dark:border-amber-500/20 dark:bg-slate-900"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium text-amber-700 dark:text-amber-300">
                                                {flag.matched_employee?.full_name ?? '—'}
                                            </span>
                                            <span className="text-xs font-mono text-amber-600 dark:text-amber-400">
                                                {t('employees.riskScore')} {flag.risk_score}
                                            </span>
                                        </div>
                                        <div className="mt-1 text-xs text-gray-500 dark:text-slate-400">
                                            #{flag.matched_employee?.employee_number} · {t('employees.matchedFields')}: {flag.matched_fields.join(', ') || '—'}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* ── Documents ────────────────────────────────────── */}
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100 mb-4">
                            {t('employees.documentMetadata')}
                        </h3>
                        <div className="space-y-2 text-sm">
                            {(employee.documents ?? []).length === 0 ? (
                                <p className="text-gray-400 dark:text-slate-500">{t('employees.noDocuments')}</p>
                            ) : (employee.documents ?? []).map((doc) => (
                                <div
                                    key={doc.id}
                                    className="flex items-center justify-between gap-3 rounded-card border border-gray-100 bg-gray-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-950"
                                >
                                    <div className="min-w-0">
                                        <span className="font-medium text-gray-800 dark:text-slate-200">{doc.document_type}</span>
                                        <span className="mx-2 text-gray-300 dark:text-slate-600">·</span>
                                        <span className="font-mono text-xs text-gray-500 dark:text-slate-400">{doc.storage_disk}</span>
                                    </div>
                                    <span className={`text-xs font-medium ${doc.is_private ? 'text-red-500' : 'text-green-600'}`}>
                                        {doc.is_private ? t('common.private') : t('common.public')}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
