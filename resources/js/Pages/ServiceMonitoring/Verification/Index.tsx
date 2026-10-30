import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PageHeader from '@/Components/PageHeader';
import { ListPagination } from '@/Components/ui';
import { inputCls, labelCls, panelCls, primaryBtn, scrollCls, secondaryBtn, tdCls, thCls, useSm } from '@/Components/serviceMonitoring/helpers';
import ReasonDialog from '@/Components/serviceMonitoring/ReasonDialog';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import type { Named, Paginated, ServiceLevel, TechnologyType, VerificationStatus } from '@/types/serviceMonitoring';
import { Head, router } from '@inertiajs/react';
import { useState, type JSX } from 'react';

type Row = {
    id: string; level: ServiceLevel; service_name_am: string | null; service_name_en: string | null; parent: { service_name_am: string | null; service_name_en: string | null } | null;
    is_catalog_gap: boolean; technology_type: TechnologyType; technology_other: string | null; system_name: string; system_count: number; go_live_on: string;
    remark: string | null; verification_status: VerificationStatus; rejection_reason: string | null; submission_id: string; period_label: string; organization: Named;
};

/** The Innovation & Technology Bureau's queue (D20): verify or reject reported digital services. */
export default function VerificationIndex({ services, filters }: { services: Paginated<Row>; filters: { status: VerificationStatus } }): JSX.Element {
    const { sm, name } = useSm();
    const [rejecting, setRejecting] = useState<string | null>(null);
    const serviceName = (r: { service_name_am: string | null; service_name_en: string | null }) => name({ name_am: r.service_name_am, name_en: r.service_name_en });

    return (
        <AuthenticatedLayout header={<PageHeader title={sm('verification.title')} description={sm('verification.description')} />}>
            <Head title={sm('verification.title')} />
            <div className="space-y-4">
                <div className={`${panelCls} p-3`}>
                    <label htmlFor="sm-v-status" className={labelCls}>{sm('common.status')}</label>
                    <select id="sm-v-status" className={inputCls} value={filters.status} onChange={(e) => router.get(route('service-monitoring.verification.index'), { status: e.target.value }, { preserveState: true })}>
                        {(['pending', 'verified', 'rejected'] as const).map((s) => <option key={s} value={s}>{sm(`enums.verification_status.${s}`)}</option>)}
                    </select>
                </div>
                {services.data.length === 0 ? <p className={`${panelCls} p-4 text-sm text-(--app-muted-foreground)`}>{sm('verification.empty')}</p> : (
                    <div className={scrollCls}>
                        <table className="min-w-full border-collapse">
                            <thead><tr>{['common.institution', 'edit.section3.nameAm', 'edit.section3.technology', 'edit.section3.goLive', 'common.status', 'common.actions'].map((k) => <th key={k} scope="col" className={thCls}>{sm(k)}</th>)}</tr></thead>
                            <tbody>
                                {services.data.map((r) => (
                                    <tr key={r.id}>
                                        <td className={tdCls}>{name(r.organization)}<p className="text-xs text-(--app-muted-foreground)">{r.period_label}</p></td>
                                        <td className={tdCls}>
                                            {serviceName(r)}{r.is_catalog_gap && <span className="ms-1 text-xs">({sm('edit.section3.catalogGap')})</span>}
                                            {r.parent && <p className="text-xs text-(--app-muted-foreground)">{sm('verification.parent', { name: serviceName(r.parent) })}</p>}
                                        </td>
                                        <td className={tdCls}>{sm(`enums.technology_type.${r.technology_type}`)}{r.technology_other ? ` (${r.technology_other})` : ''} / {r.system_name} × {r.system_count}</td>
                                        <td className={tdCls}><LocalizedDateDisplay value={r.go_live_on} /></td>
                                        <td className={tdCls}>{sm(`enums.verification_status.${r.verification_status}`)}{r.rejection_reason ? ` — ${r.rejection_reason}` : ''}</td>
                                        <td className={tdCls}>
                                            <div className="flex flex-wrap gap-2">
                                                {r.verification_status !== 'verified' && <button type="button" className={primaryBtn} onClick={() => router.post(route('service-monitoring.verification.verify', r.id), {}, { preserveScroll: true })}>{sm('verification.verify')}</button>}
                                                {r.verification_status !== 'rejected' && <button type="button" className={secondaryBtn} onClick={() => setRejecting(r.id)}>{sm('verification.reject')}</button>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <ListPagination meta={services} />
                    </div>
                )}
            </div>
            <ReasonDialog show={rejecting !== null} title={sm('verification.reject')} label={sm('verification.rejectReason')} url={rejecting ? route('service-monitoring.verification.reject', rejecting) : '#'} onClose={() => setRejecting(null)} />
        </AuthenticatedLayout>
    );
}
