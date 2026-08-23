import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import IdCardFront from '@/Components/IdCards/IdCardFront';
import IdCardBack from '@/Components/IdCards/IdCardBack';
import CardDataChecklist from '@/Components/IdCards/CardDataChecklist';
import CardStatusBadge from '@/Components/IdCards/CardStatusBadge';
import { formatDateDisplay } from '@/lib/calendar/dateFormat';
import { useCalendarSystem } from '@/lib/calendar/calendarSystem';
import { Head } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';

type CardData = {
    id: string;
    card_number: string;
    status: string;
    issued_at?: string | null;
    expires_at?: string | null;
    qr_payload?: string | null;
    public_card_uuid?: string | null;
    employee?: {
        employee_number: string;
        full_name: string;
        metadata?: { name_en?: string | null; name_am?: string | null } | null;
        name_en?: string | null;
        gender?: string | null;
        status: string;
        photo_path?: string | null;
        photo_url?: string | null;
        current_assignment?: {
            assignment_status?: string | null;
            organization?: { name_en: string; name_am?: string | null; logo_url?: string | null } | null;
            organization_unit?: { name_en: string; name_am?: string | null } | null;
            position?: { title_en: string; title_am?: string | null; job_position_code?: string | null; grade_level?: string | null } | null;
        } | null;
    } | null;
};

type PageProps = {
    card: CardData;
    can?: { print?: boolean };
};

export default function IdCardPreview({ card, can }: PageProps) {
    const { t, locale } = useLocale();
    const calendarSystem = useCalendarSystem();

    return (
        <AuthenticatedLayout
            header={
                <PageHeader
                    title={t('idCards.previewBeforePrint')}
                    description={card.card_number}
                />
            }
        >
            <Head title={t('idCards.previewBeforePrint')} />

            <div className="grid gap-6 lg:grid-cols-[1fr_300px]">
                <div className="space-y-6">
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div className="mb-4 flex items-center gap-3">
                            <h3 className="font-semibold text-gray-900 dark:text-slate-100">{t('idCards.cardFront')}</h3>
                            <CardStatusBadge status={card.status} />
                        </div>
                        <div className="max-w-sm">
                            <IdCardFront
                                cardNumber={card.card_number}
                                fullName={card.employee?.name_en ?? card.employee?.metadata?.name_en ?? card.employee?.full_name}
                                fullNameAm={card.employee?.metadata?.name_am ?? card.employee?.full_name}
                                employeeNumber={card.employee?.employee_number}
                                organizationName={card.employee?.current_assignment?.organization?.name_en}
                                organizationNameAm={card.employee?.current_assignment?.organization?.name_am}
                                organizationUnitName={locale === 'am' ? card.employee?.current_assignment?.organization_unit?.name_am : card.employee?.current_assignment?.organization_unit?.name_en}
                                organizationLogoUrl={card.employee?.current_assignment?.organization?.logo_url}
                                positionTitle={card.employee?.current_assignment?.position?.title_en}
                                positionTitleAm={card.employee?.current_assignment?.position?.title_am}
                                positionCode={card.employee?.current_assignment?.position?.job_position_code}
                                jobGrade={card.employee?.current_assignment?.position?.grade_level}
                                employmentStatus={card.employee?.status}
                                gender={card.employee?.gender}
                                photoUrl={card.employee?.photo_url}
                                issueDate={card.issued_at ? (formatDateDisplay(card.issued_at.slice(0, 10), calendarSystem, locale) || card.issued_at.slice(0, 10)) : undefined}
                                expiryDate={card.expires_at ? (formatDateDisplay(card.expires_at.slice(0, 10), calendarSystem, locale) || card.expires_at.slice(0, 10)) : undefined}
                            />
                        </div>
                    </div>
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 font-semibold text-gray-900 dark:text-slate-100">{t('idCards.cardBack')}</h3>
                        <div className="max-w-sm">
                            <IdCardBack
                                cardNumber={card.card_number}
                                qrValue={card.public_card_uuid ? route('id-checker.show', card.public_card_uuid) : null}
                                issueDate={card.issued_at ? (formatDateDisplay(card.issued_at.slice(0, 10), calendarSystem, locale) || card.issued_at.slice(0, 10)) : undefined}
                                expiryDate={card.expires_at ? (formatDateDisplay(card.expires_at.slice(0, 10), calendarSystem, locale) || card.expires_at.slice(0, 10)) : undefined}
                            />
                        </div>
                    </div>
                </div>

                <div className="space-y-4">
                    {card.employee && (
                        <CardDataChecklist employee={card.employee} />
                    )}

                    {can?.print && card.status === 'pending_print' && (
                        <div className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                            <a
                                href={route('print-batches.create')}
                                className="block w-full rounded-lg bg-blue-600 px-4 py-2 text-center text-sm font-medium text-white hover:bg-blue-700"
                            >
                                {t('idCards.createPrintBatch')}
                            </a>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
