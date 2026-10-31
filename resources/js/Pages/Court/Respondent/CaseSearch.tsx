import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { SearchIcon } from '@/Components/Icons';
import { Alert, Button, Card, FormField, Input } from '@/Components/ui';
import { useRespondentText } from '@/court/i18n/useRespondentText';
import type { RespondentCaseSearchProps } from '@/court/types/respondent';
import { useStatusLabels } from './Partials/status';

/**
 * Find a case by its number and access code. A match opens the case to this respondent (and lists it under
 * My Cases); a wrong code and an unknown number get different messages, as on the Blade page.
 */
export default function RespondentCaseSearch({ caseNumber, case: found, codeError }: RespondentCaseSearchProps) {
    const { t } = useRespondentText();
    const { caseStatus } = useStatusLabels();
    // The access code is never sent back to the page or put in a URL: the form posts and is redirected.
    const [values, setValues] = useState({ case_number: caseNumber, case_code: '' });
    const [searching, setSearching] = useState(false);
    const title = t('cm.respondent.find_case_title');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(route('court.respondent.case.search.submit'), values, {
            preserveScroll: true,
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    };

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={title} description={t('cm.respondent.find_case_description')} />}>
            <Head title={title} />

            <div className="space-y-6">
                <Card>
                    <form onSubmit={submit} className="space-y-4" role="search">
                        <FormField label={t('cm.respondent.case_number_label')}>
                            {({ id }) => (
                                <Input id={id} name="case_number" value={values.case_number} autoComplete="off"
                                    placeholder={t('cm.respondent.case_number_placeholder')}
                                    onChange={(event) => setValues((current) => ({ ...current, case_number: event.target.value }))} />
                            )}
                        </FormField>
                        <FormField label={t('cm.respondent.case_access_code_label')}>
                            {({ id }) => (
                                <Input id={id} name="case_code" value={values.case_code} autoComplete="off"
                                    placeholder={t('cm.respondent.case_access_code_placeholder')}
                                    onChange={(event) => setValues((current) => ({ ...current, case_code: event.target.value }))} />
                            )}
                        </FormField>
                        <Button type="submit" loading={searching} icon={<SearchIcon className="h-4 w-4" />}>{t('cm.respondent.search')}</Button>
                    </form>
                </Card>

                {caseNumber !== '' && (found ? (
                    <Card className="space-y-3" aria-live="polite">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="text-xs font-semibold uppercase tracking-wide text-primary">{t('cm.respondent.case_found')}</div>
                                <div className="mt-1 font-mono text-lg font-semibold text-(--app-foreground)">{found.case_number}</div>
                            </div>
                            <Link href={found.show_url} className="text-sm font-medium text-primary hover:underline">{t('cm.respondent.view_case_details')}</Link>
                        </div>
                        {found.title && <p className="text-sm text-(--app-foreground)">{found.title}</p>}
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt className="text-xs uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.respondent.status_label')}</dt>
                                <dd className="font-medium text-(--app-foreground)">{caseStatus(found.status)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs uppercase tracking-wide text-(--app-muted-foreground)">{t('cm.respondent.filed')}</dt>
                                <dd className="font-medium text-(--app-foreground)"><LocalizedDateDisplay value={found.created_at} /></dd>
                            </div>
                        </dl>
                    </Card>
                ) : (
                    <Alert tone="danger">{codeError ? t('cm.respondent.case_invalid_access') : t('cm.respondent.no_matching_case')}</Alert>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
