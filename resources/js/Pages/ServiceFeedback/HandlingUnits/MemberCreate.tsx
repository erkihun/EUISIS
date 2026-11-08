import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { useLocale } from '@/hooks/useLocale';
import { Head } from '@inertiajs/react';
import MemberForm, { unitsIndexUrl, type HandlingUnitRef, type Responsibility } from './MemberForm';

/** Register a member of a handling unit with their responsibility and signature (ADR 0034). */
export default function HandlingUnitMemberCreate({ unit, responsibilities }: { unit: HandlingUnitRef; responsibilities: Responsibility[] }) {
    const { t } = useLocale();
    const title = t('serviceFeedback.handling.addMember');

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={t('serviceFeedback.handling.memberHint')} backHref={unitsIndexUrl(unit.case_type)} />}>
            <Head title={title} />
            <MemberForm unit={unit} member={null} responsibilities={responsibilities} />
        </AuthenticatedLayout>
    );
}
