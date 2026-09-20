import PublicLayout, { type PublicPageMeta } from '@/Layouts/PublicLayout';
import {
    PublicCardDecor,
    PublicPageHeader,
    PublicSection,
    RichText,
    publicCardClass,
    publicHeadingClass,
} from '@/Components/public/PublicPage';
import { useBilingual } from '@/Components/public/bilingual';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import type { PageSection } from '@/Components/public/PublicPage';

type Faq = {
    id: string;
    category: string | null;
    question_en: string;
    question_am: string | null;
    answer_html_en: string;
    answer_html_am: string;
};

interface Props {
    sections: Record<string, PageSection>;
    faqs: Faq[];
    meta: PublicPageMeta;
}

/**
 * Public support: how to reach the institution, and answers to common
 * questions — all maintained in Public Site Management.
 *
 * There is no ticket form because there is no ticket workflow behind one; a
 * form that went nowhere would be worse than a phone number.
 */
export default function Support({ sections, faqs, meta }: Props) {
    const pick = useBilingual();
    const { t } = useLocale();
    const { getString } = useSystemSettings();

    const header = sections.header;
    const contact = sections.contact;
    const title = pick(header, 'title') || t('nav.support');

    const email = getString('general.support_email');
    const phone = getString('general.support_phone');
    const helpCenter = getString('general.help_center_url');
    const office = {
        hours_en: getString('public_site.office_hours_en'),
        hours_am: getString('public_site.office_hours_am'),
        location_en: getString('public_site.office_location_en'),
        location_am: getString('public_site.office_location_am'),
    };
    const hours = pick(office, 'hours');
    const location = pick(office, 'location');

    const rows = [
        email && { label: t('publicSite.email'), value: <a href={`mailto:${email}`} className="text-[color:var(--color-primary)] hover:underline">{email}</a> },
        phone && { label: t('publicSite.phone'), value: <a href={`tel:${phone.replace(/\s+/g, '')}`} className="text-[color:var(--color-primary)] hover:underline">{phone}</a> },
        hours && { label: t('publicSite.officeHours'), value: hours },
        location && { label: t('publicSite.location'), value: location },
        helpCenter && {
            label: t('publicSite.helpCenter'),
            value: <a href={helpCenter} className="text-[color:var(--color-primary)] hover:underline [overflow-wrap:anywhere]" rel="noopener noreferrer">{helpCenter}</a>,
        },
    ].filter(Boolean) as Array<{ label: string; value: React.ReactNode }>;

    return (
        <PublicLayout title={title} description={pick(header, 'subtitle')} meta={meta}>
            <PublicPageHeader title={title} description={pick(header, 'subtitle')} breadcrumbs={[{ label: title }]} />

            <PublicSection tone="muted">
                <div className="grid gap-8 lg:grid-cols-[360px_minmax(0,1fr)]">
                    <section aria-labelledby="contact-heading" className={`${publicCardClass} min-w-0 self-start`}>
                        <PublicCardDecor glow={false} />
                        <h2 id="contact-heading" className="relative text-lg font-bold text-gray-900 dark:text-slate-100">
                            {pick(contact, 'title') || t('publicSite.contactTitle')}
                        </h2>

                        {rows.length > 0 && (
                            <dl className="relative mt-4 divide-y divide-gray-200 dark:divide-slate-800">
                                {rows.map((row) => (
                                    <div key={row.label} className="py-3">
                                        <dt className="text-xs font-medium text-gray-500 dark:text-slate-400">{row.label}</dt>
                                        <dd className="mt-0.5 text-sm text-gray-900 [overflow-wrap:anywhere] dark:text-slate-100">{row.value}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}

                        <RichText html={pick(contact, 'body_html')} className="mt-4 text-sm text-gray-600 dark:text-slate-400" />
                    </section>

                    {sections.faq && faqs.length > 0 && (
                        <section aria-labelledby="faq-heading" className="min-w-0">
                            <h2 id="faq-heading" className={publicHeadingClass}>
                                {pick(sections.faq, 'title') || t('publicSite.faqTitle')}
                            </h2>
                            {/* Native <details>: keyboard and screen-reader support
                                for free, and it works before any script loads. */}
                            <div className="mt-6 space-y-3">
                                {faqs.map((faq) => (
                                    <details key={faq.id} className={`${publicCardClass} group py-2`}>
                                        <summary className="relative flex min-h-[44px] cursor-pointer list-none items-center justify-between gap-3 py-2 text-sm font-semibold text-gray-900 marker:hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:text-slate-100 [&::-webkit-details-marker]:hidden">
                                            <span className="[overflow-wrap:anywhere]">{pick(faq, 'question')}</span>
                                            <span aria-hidden="true" className="shrink-0 text-gray-400 transition-transform group-open:rotate-45">+</span>
                                        </summary>
                                        <RichText html={pick(faq, 'answer_html')} className="relative pb-2 text-sm text-gray-700 dark:text-slate-300" />
                                    </details>
                                ))}
                            </div>
                        </section>
                    )}
                </div>
            </PublicSection>
        </PublicLayout>
    );
}
