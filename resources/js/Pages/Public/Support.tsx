import PublicLayout, { type PublicPageMeta } from '@/Layouts/PublicLayout';
import {
    PublicCardDecor,
    PublicPageHeader,
    PublicSection,
    RichText,
    publicCardClass,
    publicCardInteractiveClass,
    publicHeadingClass,
} from '@/Components/public/PublicPage';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
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
                <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_340px]">
                    <div className="flex min-w-0 flex-col gap-10">
                        <section aria-labelledby="help-heading" className="min-w-0">
                            <h2 id="help-heading" className={publicHeadingClass}>{t('publicSite.helpTitle')}</h2>
                            <div className="mt-6 grid gap-4 sm:grid-cols-2">
                                {HELP_ROUTES.map((item) => <HelpCard key={item.name} {...item} />)}
                            </div>
                        </section>

                        {sections.faq && (
                            <section aria-labelledby="faq-heading" className="min-w-0">
                                <h2 id="faq-heading" className={publicHeadingClass}>
                                    {pick(sections.faq, 'title') || t('publicSite.faqTitle')}
                                </h2>
                                {/* Native <details>: keyboard and screen-reader support
                                    for free, and it works before any script loads. */}
                                <div className="mt-6 space-y-3">
                                    {faqs.length > 0 ? faqs.map((faq) => (
                                        <FaqItem key={faq.id} question={pick(faq, 'question')}>
                                            <RichText html={pick(faq, 'answer_html')} className="relative pb-2 text-sm text-gray-700 dark:text-slate-300" />
                                        </FaqItem>
                                    )) : DEFAULT_FAQS.map((n) => (
                                        // Until administrators add their own questions, the home page's answers apply.
                                        <FaqItem key={n} question={t(`home.faq${n}Q`)}>
                                            <p className="relative pb-2 text-sm text-gray-700 dark:text-slate-300">{t(`home.faq${n}A`)}</p>
                                        </FaqItem>
                                    ))}
                                </div>
                            </section>
                        )}
                    </div>

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
                </div>
            </PublicSection>
        </PublicLayout>
    );
}

const DEFAULT_FAQS = [1, 2, 3, 4, 5, 6, 7, 8];

type HelpRoute = { name: string; routeName?: string; link?: string; tone: string; icon: ReactNode };

const iconProps = { width: 20, height: 20, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round' as const, strokeLinejoin: 'round' as const, 'aria-hidden': true };

/** Where visitors most often need to go. Feedback has no page of its own: it starts from the employee's QR code. */
const HELP_ROUTES: HelpRoute[] = [
    { name: 'Verify', routeName: 'public.verify', link: 'home.heroCtaVerify', tone: 'bg-blue-50 text-[color:var(--color-primary)] dark:bg-slate-800 dark:text-blue-300', icon: <svg {...iconProps}><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><path d="M14 14h3v3h-3zM17 20h3v-3" /></svg> },
    { name: 'Portal', routeName: 'employee.login', link: 'home.heroCtaPortal', tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300', icon: <svg {...iconProps}><circle cx="12" cy="8" r="4" /><path d="M4 21a8 8 0 0116 0" /></svg> },
    { name: 'Feedback', tone: 'bg-orange-50 text-[color:var(--color-accent)] dark:bg-slate-800 dark:text-orange-300', icon: <svg {...iconProps}><path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z" /></svg> },
    { name: 'Services', routeName: 'public.services', link: 'home.heroCtaServices', tone: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200', icon: <svg {...iconProps}><path d="M3 9l1.5-5h15L21 9M4 9v11h16V9M3 9h18" /></svg> },
];

function HelpCard({ name, routeName, link, tone, icon }: HelpRoute) {
    const { t } = useLocale();
    const body = (
        <>
            <span className={`inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-[10px] ${tone}`}>{icon}</span>
            <span className="flex min-w-0 flex-col gap-1.5">
                <span className="text-[15px] font-semibold text-gray-900 dark:text-slate-100">{t(`publicSite.help${name}Title`)}</span>
                <span className="text-justify text-[13px] leading-relaxed text-gray-600 dark:text-slate-400">{t(`publicSite.help${name}Desc`)}</span>
                {link && <span className="text-[13px] font-semibold text-[color:var(--color-primary)]">{t(link)} <span aria-hidden="true">→</span></span>}
            </span>
        </>
    );
    return routeName ? (
        <Link href={route(routeName)} className={`${publicCardInteractiveClass} flex h-full gap-4`}>{body}</Link>
    ) : (
        <div className={`${publicCardClass} flex h-full gap-4`}>{body}</div>
    );
}

function FaqItem({ question, children }: { question: string; children: ReactNode }) {
    return (
        <details className={`${publicCardClass} group py-2`}>
            <summary className="relative flex min-h-[44px] cursor-pointer list-none items-center justify-between gap-3 py-2 text-sm font-semibold text-gray-900 marker:hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:text-slate-100 [&::-webkit-details-marker]:hidden">
                <span className="[overflow-wrap:anywhere]">{question}</span>
                <span aria-hidden="true" className="shrink-0 text-gray-400 transition-transform group-open:rotate-45">+</span>
            </summary>
            {children}
        </details>
    );
}
