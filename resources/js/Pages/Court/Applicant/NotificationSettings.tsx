import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Button, Card, buttonClassName } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import type { ApplicantNotificationSettingsProps } from '@/court/types/applicant';

const OPTIONS = [
    ['email_status', 'status'],
    ['email_hearing', 'hearing'],
    ['email_message', 'message'],
    ['email_weekly_digest', 'weekly_digest'],
] as const;

/** Which emails the applicant gets: case status changes, hearings, court messages and a weekly digest. */
export default function ApplicantNotificationSettings({ prefs }: ApplicantNotificationSettingsProps) {
    const { t } = useLocale();
    const form = useForm({ ...prefs });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(route('court.applicant.notifications.settings.update'), { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout contentWidth="form" header={<PageHeader title={t('cm.app.notification_preferences.title')} backHref={route('court.applicant.notifications.index')} />}>
            <Head title={t('cm.app.notification_preferences.title')} />
            <form onSubmit={submit} className="max-w-xl">
                <Card className="space-y-5">
                    <h2 className="text-base font-semibold text-(--app-foreground)">{t('cm.app.notification_preferences.email_title')}</h2>
                    <div className="space-y-4">
                        {OPTIONS.map(([field, key]) => (
                            <label key={field} className="flex items-start gap-3">
                                <input type="checkbox" name={field} checked={form.data[field]} onChange={(event) => form.setData(field, event.target.checked)} className="mt-1 h-4 w-4 rounded border-(--app-border-strong) text-primary focus:ring-primary" />
                                <span>
                                    <span className="block font-medium text-(--app-foreground)">{t(`cm.app.notification_preferences.${key}_label`)}</span>
                                    <span className="block text-sm text-(--app-muted-foreground)">{t(`cm.app.notification_preferences.${key}_desc`)}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <div className="flex flex-wrap items-center gap-2 border-t border-(--app-border) pt-4">
                        <Button type="submit" disabled={form.processing}>{t('cm.app.notification_preferences.save')}</Button>
                        <Link href={route('court.applicant.notifications.index')} className={buttonClassName({ variant: 'ghost' })}>&larr; {t('cm.app.notification_preferences.back')}</Link>
                    </div>
                </Card>
            </form>
        </AuthenticatedLayout>
    );
}
