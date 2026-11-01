import { useSystemSettings } from '@/hooks/useSystemSettings';
import { usePublicText } from '@/court/i18n/usePublicText';
import { CourtMark, useCourtName } from '@/court/public/CourtPublicHeader';
import type { PublicFooter } from '@/court/types/public';

/** The landing manager's social-media fields, in the order its footer form lists them. */
export const SOCIAL_LINKS: Array<{ field: keyof PublicFooter; label: string; icon: string }> = [
    { field: 'social_facebook', label: 'Facebook', icon: 'M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z' },
    { field: 'social_twitter', label: 'Twitter / X', icon: 'M23 3a10.9 10.9 0 01-3.14 1.53 4.48 4.48 0 00-7.86 3v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5a4.5 4.5 0 00-.08-.83A7.72 7.72 0 0023 3z' },
    { field: 'social_linkedin', label: 'LinkedIn', icon: 'M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6zM2 9h4v12H2z M2 6.5a2 2 0 114 0 2 2 0 01-4 0z' },
    { field: 'social_youtube', label: 'YouTube', icon: 'M22.54 6.42a2.78 2.78 0 00-1.94-1.96C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 00-1.94 1.96A29 29 0 001 12a29 29 0 00.46 5.58A2.78 2.78 0 003.4 19.54C5.12 20 12 20 12 20s6.88 0 8.6-.46a2.78 2.78 0 001.94-1.96A29 29 0 0023 12a29 29 0 00-.46-5.58zM9.75 15.02V8.98L15.5 12l-5.75 3.02z' },
    { field: 'social_telegram', label: 'Telegram', icon: 'M22 2L11 13M22 2L15 22l-4-9-9-4 20-7z' },
    { field: 'social_instagram', label: 'Instagram', icon: 'M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37zM17.5 6.5h.01M7.8 2h8.4C19.4 2 22 4.6 22 7.8v8.4a5.8 5.8 0 01-5.8 5.8H7.8C4.6 22 2 19.4 2 16.2V7.8A5.8 5.8 0 017.8 2z' },
];

const linkClass = 'inline-flex min-h-10 max-w-full items-center py-1.5 text-sm text-slate-300 wrap-anywhere hover:text-white lg:min-h-8 lg:py-1';
const heading = 'mb-3 text-xs font-semibold uppercase tracking-[0.16em] text-(--court-brass-soft)';

/**
 * The public footer: the court's mark and what it is, its pages, its
 * publications and the registry's contact details, as the landing manager
 * saved them for the page's language. A column renders only when it has
 * content; nothing is filled with placeholders. No staff sign-in.
 */
export default function CourtPublicFooter({ footer, userManualEnabled, userManualTitle = null }: {
    footer: PublicFooter | null;
    userManualEnabled: boolean;
    userManualTitle?: string | null;
}) {
    const { t } = usePublicText();
    const { getString } = useSystemSettings();
    const courtName = useCourtName();
    const text = (key: string) => t(`court.publicSite.${key}`);
    const home = route('court.landing.home');

    const description = footer?.description || t('cm.home.footer.description');
    const email = footer?.contact_email || getString('general.support_email');
    const phone = footer?.contact_phone || getString('general.support_phone');
    const address = footer?.contact_address || '';
    const socials = SOCIAL_LINKS.filter((social) => Boolean(footer?.[social.field]));

    return (
        <footer className="bg-(--court-ink) pb-[env(safe-area-inset-bottom)] text-white wrap-anywhere">
            <div aria-hidden="true" className="h-[3px] bg-linear-to-r from-transparent via-(--court-brass) to-transparent" />
            <div className="mx-auto grid max-w-7xl grid-cols-1 gap-8 px-4 py-12 sm:grid-cols-2 sm:px-6 lg:grid-cols-[1.8fr_1fr_1fr_1.4fr] lg:gap-12 lg:px-8 *:min-w-0">
                <div className="sm:col-span-2 lg:col-span-1">
                    <div className="flex items-center gap-3">
                        <CourtMark size="lg" />
                        <div className="min-w-0">
                            <p className="text-base font-semibold leading-tight text-white">{courtName}</p>
                            <p className="mt-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-(--court-brass)">{footer?.authority_name || text('authority')}</p>
                        </div>
                    </div>
                    <p className="mt-4 max-w-md text-sm leading-relaxed text-slate-300">{description}</p>
                    {socials.length > 0 && (
                        <ul className="mt-5 flex flex-wrap gap-2" aria-label={text('footer.follow')}>
                            {socials.map((social) => (
                                <li key={social.field}>
                                    <a
                                        href={footer?.[social.field] ?? '#'}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        aria-label={social.label}
                                        className="flex h-10 w-10 items-center justify-center rounded-full border border-white/15 text-slate-300 transition hover:border-(--court-brass) hover:text-(--court-brass)"
                                    >
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" d={social.icon} />
                                        </svg>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <nav aria-label={text('footer.court')}>
                    <p className={heading}>{text('footer.court')}</p>
                    <ul>
                        <li><a href={`${home}#about`} className={linkClass}>{text('nav.about')}</a></li>
                        <li><a href={`${home}#hearings`} className={linkClass}>{text('nav.hearings')}</a></li>
                        <li><a href={route('court.public.news')} className={linkClass}>{text('nav.news')}</a></li>
                        <li><a href={`${home}#contact`} className={linkClass}>{text('nav.contact')}</a></li>
                    </ul>
                </nav>

                <nav aria-label={text('footer.publications')}>
                    <p className={heading}>{text('footer.publications')}</p>
                    <ul>
                        <li><a href={route('court.public.resources')} className={linkClass}>{text('nav.publications')}</a></li>
                        {userManualEnabled && <li><a href={route('court.landing.user-manual')} className={linkClass}>{userManualTitle || t('cm.home.nav.user_manual')}</a></li>}
                        <li><a href={route('court.public.terms')} className={linkClass}>{text('nav.terms')}</a></li>
                    </ul>
                </nav>

                {(email || phone || address || footer?.office_hours) && (
                    <div>
                        <p className={heading}>{text('footer.contact')}</p>
                        <ul className="space-y-1 text-sm text-slate-300">
                            {address && <li className="py-1.5 lg:py-1">{address}</li>}
                            {phone && <li><a href={`tel:${phone.replace(/\s+/g, '')}`} className={linkClass}>{phone}</a></li>}
                            {email && <li><a href={`mailto:${email}`} className={linkClass}>{email}</a></li>}
                            {footer?.office_hours && <li className="py-1.5 lg:py-1">{footer.office_hours}</li>}
                        </ul>
                    </div>
                )}
            </div>

            <div className="border-t border-white/10">
                <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-5 text-xs text-slate-400 sm:flex-row sm:items-center sm:justify-between sm:px-6 lg:px-8">
                    <p>© {new Date().getFullYear()} {courtName}. {text('footer.rights')}</p>
                    <a href="#main-content" className="text-slate-400 hover:text-white">{text('footer.top')} ↑</a>
                </div>
            </div>
        </footer>
    );
}
