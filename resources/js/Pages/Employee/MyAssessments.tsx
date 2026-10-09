import PortalPage from '@/Components/employees/portal/PortalPage';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Details, Empty, Section, formatScore, inputCls, primaryBtn } from '@/Components/performance/ui';
import { RecordStatusBadge, usePick } from '@/Components/assessmentWorkspace/kit';
import { useLocale } from '@/hooks/useLocale';
import { useForm } from '@inertiajs/react';

type Record = { id: string; status: string; form: { name_en: string; name_am: string | null; version_no: number } | null; cycle: { code: string; name_en: string; name_am: string | null } | null; period_start: string; period_end: string; percentage: string | null; contribution: string | null; band: { code: string; label_en: string | null; label_am: string | null } | null; components: { type: string; percentage: string | null; anonymous: boolean }[]; acknowledgement_required: boolean; acknowledged_at: string | null; can_acknowledge: boolean };

export default function MyAssessments({ records }: { records: Record[] }) {
    const { t } = useLocale(); const pick = usePick();
    return <PortalPage title={t('assessmentWorkspace.myTitle')} description={t('assessmentWorkspace.myDescription')}>
        {records.length === 0 ? <Section title={t('assessmentWorkspace.myTitle')}><Empty>{t('assessmentWorkspace.my.empty')}</Empty></Section> : <div className="space-y-4">{records.map((record) => <AssessmentCard key={record.id} record={record} />)}</div>}
    </PortalPage>;
}

function AssessmentCard({ record }: { record: Record }) {
    const { t } = useLocale(); const pick = usePick(); const form = useForm({ comment: '' });
    return <Section title={record.form ? `${pick(record.form.name_en, record.form.name_am)} · v${record.form.version_no}` : '—'} actions={<RecordStatusBadge value={record.status} />}>
        <Details columns={3} items={[[t('assessmentWorkspace.columns.cycle'), record.cycle ? pick(record.cycle.name_en, record.cycle.name_am) : '—'], [t('assessmentWorkspace.context.period'), <><LocalizedDateDisplay value={record.period_start} /> – <LocalizedDateDisplay value={record.period_end} /></>], [t('assessmentWorkspace.my.result'), record.percentage === null ? t('assessmentWorkspace.my.pending') : `${formatScore(record.percentage)}%`], [t('assessmentWorkspace.review.contribution'), formatScore(record.contribution)], [t('assessmentWorkspace.my.band'), record.band ? pick(record.band.label_en, record.band.label_am) : '—'], [t('assessmentWorkspace.my.components'), record.components.map((component) => `${t(`assessmentWorkspace.evaluatorTypes.${component.type}`)}: ${component.percentage === null ? '—' : `${formatScore(component.percentage)}%`}`).join(' · ') || '—']]} />
        {record.acknowledged_at && <p className="mt-4 text-sm text-gray-500">{t('assessmentWorkspace.my.acknowledged')}: <LocalizedDateDisplay value={record.acknowledged_at} withTime /></p>}
        {record.can_acknowledge && <form className="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4 dark:border-slate-800" onSubmit={(event) => { event.preventDefault(); form.post(route('employee.assessments.acknowledge', record.id), { preserveScroll: true }); }}><label className="min-w-64 flex-1 text-sm"><span className="mb-1 block font-medium">{t('assessmentWorkspace.my.acknowledgeComment')}</span><textarea rows={2} maxLength={2000} className={inputCls} value={form.data.comment} onChange={(event) => form.setData('comment', event.target.value)} /></label><button className={primaryBtn} disabled={form.processing}>{t('assessmentWorkspace.my.acknowledge')}</button></form>}
    </Section>;
}
