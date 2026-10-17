import type { ReactNode } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useLocale } from '@/hooks/useLocale';

/** What PublicFeedbackQuestionnaire::presentForStaff sends for an organization submission. */
export type OrganizationFormDetailsData = {
    service: string | null;
    service_code: string | null;
    /** The visitor's own words: not linked to the institution's service catalog. */
    service_unstructured: boolean;
    service_point: string | null;
    service_date: string | null;
    service_time_band: 'morning' | 'afternoon' | 'evening' | null;
    questionnaire_version: number | null;
    answers: { code: string; question: string; type: string; rating: number | null; value: string | null }[];
};

/**
 * The service information and questionnaire answers of a submission made
 * through the organization feedback form, for staff. Each question is shown
 * in the wording of the version the visitor answered, so a later revision of
 * the questionnaire never changes what this submission appears to say.
 */
export default function OrganizationFormDetails({ details }: { details: OrganizationFormDetailsData }) {
    const { t } = useLocale();
    const field = (label: string, value: ReactNode) => (
        <div className="min-w-0">
            <dt className="text-[color:var(--app-muted-foreground)]">{label}</dt>
            <dd className="mt-0.5 font-medium text-[color:var(--app-foreground)] [overflow-wrap:anywhere]">{value}</dd>
        </div>
    );

    return (
        <section aria-labelledby="organization-form-heading" className="space-y-4 rounded-[var(--radius-panel)] border border-[color:var(--app-border)] bg-[color:var(--app-surface)] p-5">
            <h2 id="organization-form-heading" className="text-lg font-semibold">{t('serviceFeedback.orgFormSection')}</h2>
            <dl className="grid gap-3 text-sm sm:grid-cols-2">
                {field(t('serviceFeedback.serviceReceived'), details.service ? (
                    <>
                        {details.service_code && <span className="me-1 font-mono text-xs">{details.service_code}</span>}
                        {details.service}
                        {details.service_unstructured && <span className="mt-0.5 block text-xs font-normal text-amber-700 dark:text-amber-300">{t('serviceFeedback.serviceUnstructured')}</span>}
                    </>
                ) : '—')}
                {field(t('serviceFeedback.servicePoint'), details.service_point ?? '—')}
                {field(t('serviceFeedback.serviceDate'), details.service_date ? <LocalizedDateDisplay value={details.service_date} /> : '—')}
                {field(t('serviceFeedback.serviceTime'), details.service_time_band ? t(`serviceFeedback.timeBand_${details.service_time_band}`) : '—')}
            </dl>
            {details.questionnaire_version !== null && (
                <div className="border-t border-[color:var(--app-border)] pt-4">
                    <h3 className="text-sm font-semibold">
                        {t('serviceFeedback.questionnaireAnswers')}
                        <span className="ms-2 font-normal text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.answeredVersion').replace('{n}', String(details.questionnaire_version))}</span>
                    </h3>
                    {details.answers.length === 0 ? (
                        <p className="mt-2 text-sm text-[color:var(--app-muted-foreground)]">{t('serviceFeedback.noAnswers')}</p>
                    ) : (
                        <dl className="mt-2 divide-y divide-[color:var(--app-border)] text-sm">
                            {details.answers.map((answer) => (
                                <div key={answer.code} className="flex flex-col gap-1 py-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                                    <dt className="text-[color:var(--app-muted-foreground)]">{answer.question}</dt>
                                    <dd className="font-medium text-[color:var(--app-foreground)] sm:text-right [overflow-wrap:anywhere] whitespace-pre-wrap">
                                        {answer.rating !== null
                                            ? `${answer.rating}/5 · ${t(`serviceFeedback.rating${answer.rating}`)}`
                                            : answer.type === 'yes_no' ? t(answer.value === 'yes' ? 'common.yes' : 'common.no') : answer.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </div>
            )}
        </section>
    );
}
