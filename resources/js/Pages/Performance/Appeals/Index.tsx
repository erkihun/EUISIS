import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { Empty, Pager, Pill, Section, Table, employeeName, formatScore, pageCls, tdCls, thCls, type Paginator } from '@/Components/performance/ui';
import { useLocale } from '@/hooks/useLocale';
import { Head, Link } from '@inertiajs/react';

export type AppealRow = {
    id: string; appeal_no: string; status: string; decision: string | null; submitted_at: string; decided_at: string | null;
    employee: { name: string | null; name_en: string | null; number: string | null };
    result: { final_score: string | null; rating_en: string | null; rating_am: string | null } | null;
};

export default function AppealsIndex({ appeals }: { appeals: Paginator<AppealRow> }) {
    const { t, locale } = useLocale();
    return (
        <AuthenticatedLayout header={<PageHeader title={t('performance.appeals.title')} description={t('performance.appeals.description')} />}>
            <Head title={t('performance.appeals.title')} />
            <div className={pageCls}>
                <Section title={t('performance.appeals.title')}>
                    {appeals.data.length === 0 ? <Empty>{t('performance.appeals.empty')}</Empty> : (
                        <Table head={<>
                            <th className={thCls}>#</th>
                            <th className={thCls}>{t('performance.fields.employee')}</th>
                            <th className={thCls}>{t('performance.fields.score')}</th>
                            <th className={thCls}>{t('performance.fields.status')}</th>
                            <th className={thCls}>{t('performance.fields.from')}</th>
                        </>}>
                            {appeals.data.map((a) => (
                                <tr key={a.id}>
                                    <td className={tdCls}><Link href={route('performance.appeals.show', a.id)} className="font-medium text-[color:var(--color-primary)] hover:underline">{a.appeal_no}</Link></td>
                                    <td className={tdCls}>{employeeName(a.employee, locale)}<p className="text-xs text-gray-500">{a.employee.number}</p></td>
                                    <td className={`${tdCls} tabular-nums`}>{formatScore(a.result?.final_score)} <span className="text-xs text-gray-500">{(locale === 'am' && a.result?.rating_am) || a.result?.rating_en}</span></td>
                                    <td className={tdCls}><Pill group="appeal" value={a.status} />{a.decision && <> <Pill group="decision" value={a.decision} /></>}</td>
                                    <td className={`${tdCls} text-xs`}><LocalizedDateDisplay value={a.submitted_at} withTime /></td>
                                </tr>
                            ))}
                        </Table>
                    )}
                    <Pager page={appeals} />
                </Section>
            </div>
        </AuthenticatedLayout>
    );
}
