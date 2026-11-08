import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import MemberForm, { unitsIndexUrl, type HandlingMember, type HandlingUnitRef, type Responsibility } from './MemberForm';

/** Change a member's responsibility or replace their signature (ADR 0034). */
export default function HandlingUnitMemberEdit({ unit, member, responsibilities }: { unit: HandlingUnitRef; member: HandlingMember; responsibilities: Responsibility[] }) {
    const { t } = useLocale();
    const title = t('serviceFeedback.handling.editMember');

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={member.name ?? undefined} backHref={unitsIndexUrl(unit.case_type)} />}>
            <Head title={title} />
            <MemberForm unit={unit} member={member} responsibilities={responsibilities} />
        </AuthenticatedLayout>
    );
}
