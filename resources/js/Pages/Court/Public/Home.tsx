import { useEffect, useState, type CSSProperties, type ReactNode } from 'react';
import { cx } from '@/Components/ui';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import PublicLayout from '@/court/public/PublicLayout';
import CourtResourceCard from '@/court/public/CourtResourceCard';
import { useCourtName } from '@/court/public/CourtPublicHeader';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import { usePublicText } from '@/court/i18n/usePublicText';
import { CountUp, CourtMotes, CourtSeal, RisingWords, stagger } from '@/court/public/motion';
import type {
    PublicHearing,
    PublicHomeProps,
    PublicHomeSection,
    PublicHomeSlide,
    PublicMember,
    ServiceIcon,
} from '@/court/types/public';

/** The landing manager's service icons (Services → icon). */
const SERVICE_ICONS: Record<ServiceIcon, string> = {
    document: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    calendar: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    lock: 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
    chart: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
    database: 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
    chat: 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z',
};

const numbers = new Intl.NumberFormat('en-US');
const formatNumber = (value: number) => numbers.format(value);
const SLIDE_SECONDS = 9;

function Icon({ path, className = 'h-5 w-5' }: { path: string; className?: string }) {
    return (
        <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.7} viewBox="0 0 24 24" aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d={path} pathLength={100} />
        </svg>
    );
}

/** A home section: its anchor, the eyebrow with the brass rule, the heading and its body. */
function Section({ id, eyebrow, heading, tone = 'paper', align = 'start', aside, children }: {
    id: string;
    eyebrow: string;
    heading: PublicHomeSection;
    tone?: 'paper' | 'parchment' | 'ink';
    align?: 'start' | 'center';
    aside?: ReactNode;
    children: ReactNode;
}) {
    const dark = tone === 'ink';

    return (
        <section
            id={id}
            aria-labelledby={`${id}-heading`}
            className={cx(
                'scroll-mt-32 py-16 sm:py-24',
                tone === 'paper' && 'border-t border-(--court-line) bg-(--court-paper)',
                tone === 'parchment' && 'bg-(--court-parchment)',
                dark && 'bg-(--court-ink) text-white',
            )}
        >
            <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div data-reveal="heading" className={cx('mb-10 flex flex-col gap-6 sm:mb-14 lg:flex-row lg:items-end lg:justify-between', align === 'center' && 'items-center text-center lg:flex-col lg:items-center')}>
                    <div className="max-w-2xl">
                        <p className={cx('court-eyebrow', dark && 'court-eyebrow-light')}>{eyebrow}</p>
                        <h2 id={`${id}-heading`} className={cx('mt-4 text-3xl font-semibold tracking-tight sm:text-4xl', dark ? 'text-white' : 'text-(--court-ink) dark:text-white')}>
                            {heading.title}
                        </h2>
                        {heading.subtitle && <p className={cx('mt-4 text-base leading-relaxed', dark ? 'text-slate-300' : 'text-(--court-muted)')}>{heading.subtitle}</p>}
                    </div>
                    {aside}
                </div>
                {children}
            </div>
        </section>
    );
}

/** A quiet link at a section's head ("All news ›"). */
function MoreLink({ href, children, light = false }: { href: string; children: ReactNode; light?: boolean }) {
    return (
        <a href={href} className={cx('inline-flex min-h-11 shrink-0 items-center gap-2 text-sm font-semibold underline-offset-4 hover:underline', light ? 'text-(--court-brass-soft)' : 'text-(--court-navy) dark:text-(--court-brass-soft)')}>
            {children}
            <span aria-hidden="true">›</span>
        </a>
    );
}

/** The hero: the slide's statement and buttons, and "today at the court" beside it. */
function Hero({ slides, today, hearings, officeHours }: {
    slides: PublicHomeSlide[];
    today: PublicHomeProps['today'];
    hearings: PublicHearing[];
    officeHours: string | null;
}) {
    const { t, fill } = usePublicText();
    const text = (key: string) => t(`court.publicSite.${key}`);
    const [index, setIndex] = useState(0);
    const [paused, setPaused] = useState(false);
    const count = slides.length;
    const slide = slides[Math.min(index, count - 1)];
    const next = hearings.slice(0, 3);

    useEffect(() => {
        if (count < 2 || paused || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return undefined;
        const timer = window.setInterval(() => setIndex((value) => (value + 1) % count), SLIDE_SECONDS * 1000);
        return () => window.clearInterval(timer);
    }, [count, paused]);

    if (!slide) return null;

    return (
        <section aria-roledescription={count > 1 ? 'carousel' : undefined} aria-label={slide.title} className="court-hero">
            {slide.image_url && <div key={`image-${index}`} aria-hidden="true" className="court-hero-image" style={{ backgroundImage: `url(${slide.image_url})` }} />}
            {/* The hall: a band of window light crossing now and then, brass motes in it, the colonnade rising at the foot. */}
            <div aria-hidden="true" className="court-hero-sweep" />
            <CourtMotes />
            <svg aria-hidden="true" viewBox="0 0 1200 400" preserveAspectRatio="xMidYMax slice" className="court-hero-columns w-full">
                <g fill="currentColor">
                    <path d="M120 120 L600 20 L1080 120 Z" className="court-hall-roof" />
                    <rect x="140" y="128" width="920" height="14" className="court-hall-roof" />
                    {Array.from({ length: 8 }).map((_, i) => <rect key={i} x={180 + i * 115} y="150" width="34" height="220" className="court-hall-column" style={stagger(i)} />)}
                    <rect x="120" y="370" width="960" height="30" />
                </g>
            </svg>
            <CourtSeal className="court-hero-seal" />

            <div className="mx-auto grid max-w-7xl gap-10 px-4 pb-24 pt-14 sm:px-6 sm:pt-20 lg:grid-cols-[1.45fr_1fr] lg:gap-16 lg:px-8 lg:pb-28">
                {/* Keyed by the slide, so each new slide plays its entrance: the eyebrow's rule draws, the words rise in turn. */}
                <div key={index} className="min-w-0" aria-live={paused ? 'polite' : 'off'}>
                    {slide.badge && <p className="court-rise court-eyebrow court-eyebrow-light court-eyebrow-draw">{slide.badge}</p>}
                    <h1 className="court-rising mt-5 text-4xl font-semibold leading-[1.12] tracking-tight text-white wrap-anywhere sm:text-5xl"><RisingWords text={slide.title} /></h1>
                    {slide.description && <p className="court-rise court-pub-delay-2 mt-6 max-w-2xl text-lg leading-relaxed text-slate-300">{slide.description}</p>}
                    <div className="court-rise court-pub-delay-3 mt-9 flex flex-wrap gap-3">
                        <a href={slide.primary_href} className="court-cta group inline-flex min-h-12 items-center gap-2 rounded-md bg-(--court-brass) px-6 text-sm font-semibold text-(--court-ink) shadow-sm transition hover:bg-(--court-brass-soft)">
                            {slide.primary_label}
                            <span aria-hidden="true" className="court-arrow">›</span>
                        </a>
                        {slide.secondary_label && (
                            <a href={slide.secondary_href} className="inline-flex min-h-12 items-center rounded-md border border-white/25 px-6 text-sm font-semibold text-white transition hover:border-white/60 hover:bg-white/5">
                                {slide.secondary_label}
                            </a>
                        )}
                    </div>

                    {count > 1 && (
                        <div className="mt-10 flex items-center gap-3">
                            <button type="button" onClick={() => setIndex((index - 1 + count) % count)} aria-label={text('hero.previous')} className="flex h-10 w-10 items-center justify-center rounded-full border border-white/20 text-white hover:bg-white/10">‹</button>
                            <ol className="flex items-center gap-2">
                                {slides.map((item, i) => (
                                    <li key={i}>
                                        <button
                                            type="button"
                                            onClick={() => setIndex(i)}
                                            aria-label={fill(text('hero.slideOf'), { n: i + 1, total: count })}
                                            aria-current={i === index ? 'true' : undefined}
                                            className={cx('relative block h-1.5 overflow-hidden rounded-full transition-all', i === index ? 'w-8 bg-(--court-brass)/40' : 'w-3 bg-white/30 hover:bg-white/60')}
                                        >
                                            {/* The current slide's line fills over its nine seconds, and stops while paused. */}
                                            {i === index && <span key={index} aria-hidden="true" className={cx('court-slide-fill', paused && 'is-paused')} style={{ '--slide': `${SLIDE_SECONDS}s` } as CSSProperties} />}
                                        </button>
                                    </li>
                                ))}
                            </ol>
                            <button type="button" onClick={() => setIndex((index + 1) % count)} aria-label={text('hero.next')} className="flex h-10 w-10 items-center justify-center rounded-full border border-white/20 text-white hover:bg-white/10">›</button>
                            <button type="button" onClick={() => setPaused((value) => !value)} aria-label={paused ? text('hero.play') : text('hero.pause')} className="ms-1 flex h-10 w-10 items-center justify-center rounded-full border border-white/20 text-xs text-white hover:bg-white/10">
                                {paused ? '▶' : '❚❚'}
                            </button>
                        </div>
                    )}
                </div>

                <aside aria-labelledby="court-today" className="court-rise court-pub-delay-2 court-today self-start rounded-xl border border-white/12 bg-white/[0.06] p-6 backdrop-blur-sm sm:p-7">
                    <p id="court-today" className="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-(--court-brass-soft)">
                        {today.hearings > 0 && <span aria-hidden="true" className="court-live" />}
                        {text('hero.today')}
                    </p>
                    <LocalizedDateDisplay value={today.date} className="mt-2 block text-lg font-semibold text-white" />
                    <div className="mt-5 flex items-end gap-3 border-b border-white/10 pb-5">
                        <CountUp value={today.hearings} format={formatNumber} duration={1200} className="text-5xl font-semibold leading-none text-white tabular-nums" />
                        <span className="pb-1 text-sm text-slate-300">{today.hearings > 0 ? text('hero.hearingsToday') : text('hero.noneToday')}</span>
                    </div>
                    {next.length > 0 && (
                        <div className="mt-5">
                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">{text('hero.nextSittings')}</p>
                            <ul className="mt-3 space-y-3">
                                {next.map((hearing, i) => (
                                    <li key={hearing.id} className="court-rise flex items-start gap-3 text-sm" style={{ animationDelay: `${0.5 + i * 0.12}s` }}>
                                        <span className="mt-0.5 shrink-0 rounded bg-white/10 px-2 py-0.5 font-mono text-xs text-(--court-brass-soft)">{hearing.time ?? '—'}</span>
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium text-white">{hearing.case_number}</span>
                                            <span className="block truncate text-xs text-slate-400">
                                                <LocalizedDateDisplay value={hearing.date} />{hearing.location ? ` · ${hearing.location}` : ''}
                                            </span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <a href="#hearings" className="mt-6 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-(--court-brass-soft) hover:text-white">
                        {text('hero.fullSchedule')} <span aria-hidden="true" className="court-nudge-down">↓</span>
                    </a>
                    {officeHours && (
                        <p className="mt-4 border-t border-white/10 pt-4 text-xs text-slate-400">
                            <span className="font-semibold text-slate-300">{text('hero.officeHours')}:</span> {officeHours}
                        </p>
                    )}
                </aside>
            </div>
        </section>
    );
}

function Initials({ name }: { name: string }) {
    const letters = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0)).join('');
    return (
        <span aria-hidden="true" className="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-(--court-ink) text-xl font-semibold text-(--court-brass) ring-4 ring-(--court-parchment)">
            {letters}
        </span>
    );
}

function MemberCard({ member, index }: { member: PublicMember; index: number }) {
    return (
        // The item reveals; the card inside lifts on hover (two transitions, two elements).
        <li data-reveal style={stagger(index)} className="flex">
            <div className="court-card court-card-hover flex w-full flex-col items-center p-6 text-center">
            {member.photo_url
                ? <img src={member.photo_url} alt="" loading="lazy" decoding="async" className="h-20 w-20 shrink-0 rounded-full object-cover ring-4 ring-(--court-parchment)" />
                : <Initials name={member.name} />}
            <h3 className="mt-4 text-base font-semibold text-(--court-ink) dark:text-white">{member.name}</h3>
            {member.role && <p className="mt-1 text-xs font-semibold uppercase tracking-[0.12em] text-(--court-brass-strong)">{member.role}</p>}
            {member.bio && <p className="mt-3 line-clamp-4 text-sm leading-relaxed text-(--court-muted)">{member.bio}</p>}
            </div>
        </li>
    );
}

/** The sittings of the coming days: a table on wide screens, a list on narrow ones. */
function HearingList({ hearings, today }: { hearings: PublicHearing[]; today: string }) {
    const { t } = usePublicText();
    const col = (key: string) => t(`court.publicSite.hearings.${key}`);
    // A sitting of today carries a live brass dot beside its date.
    const todayMark = (hearing: PublicHearing) => hearing.date === today
        ? <span className="ms-2 inline-flex items-center gap-1.5 rounded-full bg-(--court-brass)/15 px-2 py-0.5 align-middle text-[11px] font-semibold text-(--court-brass-strong)"><span aria-hidden="true" className="court-live" />{col('today')}</span>
        : null;

    if (hearings.length === 0) {
        return <p data-reveal className="court-card px-6 py-10 text-center text-sm text-(--court-muted)">{col('none')}</p>;
    }

    return (
        <>
            <div data-reveal className="court-card hidden overflow-hidden md:block">
                <table className="w-full text-start text-sm">
                    <thead className="bg-(--court-parchment) text-xs uppercase tracking-[0.1em] text-(--court-muted)">
                        <tr>
                            {['date', 'time', 'case', 'caseType', 'sitting', 'room'].map((key) => <th key={key} scope="col" className="px-5 py-3.5 text-start font-semibold">{col(key)}</th>)}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-(--court-line)">
                        {hearings.map((hearing, index) => (
                            <tr key={hearing.id} data-reveal="row" style={stagger(index)} className="court-row hover:bg-(--court-parchment)/50">
                                <td className="whitespace-nowrap px-5 py-4 font-medium text-(--court-ink) dark:text-white"><LocalizedDateDisplay value={hearing.date} />{todayMark(hearing)}</td>
                                <td className="whitespace-nowrap px-5 py-4 font-mono text-(--court-text)">{hearing.time ?? '—'}</td>
                                <td className="whitespace-nowrap px-5 py-4 font-mono font-semibold text-(--court-navy) dark:text-(--court-brass-soft)">{hearing.case_number}</td>
                                <td className="px-5 py-4 text-(--court-text)">{hearing.case_type ?? '—'}</td>
                                <td className="px-5 py-4 text-(--court-text)">{hearing.type ?? '—'}</td>
                                <td className="px-5 py-4 text-(--court-text)">{hearing.location ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <ul className="space-y-3 md:hidden">
                {hearings.map((hearing, index) => (
                    <li key={hearing.id} data-reveal style={stagger(index)} className="court-card flex gap-4 p-4">
                        <span className="flex w-16 shrink-0 flex-col items-center justify-center rounded-lg bg-(--court-ink) py-2 text-center text-white">
                            <span className="font-mono text-sm font-semibold text-(--court-brass-soft)">{hearing.time ?? '—'}</span>
                        </span>
                        <span className="min-w-0 text-sm">
                            <span className="block font-mono font-semibold text-(--court-navy) dark:text-(--court-brass-soft)">{hearing.case_number}</span>
                            <span className="block text-(--court-text)"><LocalizedDateDisplay value={hearing.date} />{todayMark(hearing)}</span>
                            <span className="block text-xs text-(--court-muted)">{[hearing.case_type, hearing.type, hearing.location].filter(Boolean).join(' · ')}</span>
                        </span>
                    </li>
                ))}
            </ul>
            <p className="mt-4 text-xs text-(--court-muted)">{col('note')}</p>
        </>
    );
}

function Faq({ faqs }: { faqs: PublicHomeProps['faqs'] }) {
    return (
        <ul className="divide-y divide-(--court-line) border-y border-(--court-line)">
            {faqs.map((faq, index) => (
                <li key={index} data-reveal style={stagger(index)}>
                    <details className="court-faq group py-1">
                        <summary className="flex min-h-14 cursor-pointer list-none items-center justify-between gap-4 py-3 text-start text-base font-semibold text-(--court-ink) marker:content-none dark:text-white">
                            <span className="min-w-0 wrap-anywhere">{faq.question}</span>
                            <span aria-hidden="true" className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-(--court-line) text-(--court-brass-strong) transition group-open:rotate-45">+</span>
                        </summary>
                        <p className="whitespace-pre-line pb-5 pe-12 text-sm leading-relaxed text-(--court-muted)">{faq.answer}</p>
                    </details>
                </li>
            ))}
        </ul>
    );
}

/**
 * The public home page (PublicHomeController@home), laid out as an
 * administrative court's site: the statement and today's sittings, the
 * court's services, who it is and its members, the cause list, its figures,
 * how a case proceeds, news, publications, questions and the registry. Every
 * heading and item arrives resolved for the page's language; a section the
 * landing manager hid arrives empty and is left out.
 */
export default function PublicHome({
    slides, sections, about, members, services, hearings, today, metrics, steps, news, resources, hasMoreResources, faqs, contactVisible, publicSite,
}: PublicHomeProps) {
    const { t } = usePublicText();
    const courtName = useCourtName();
    const text = (key: string) => t(`court.publicSite.${key}`);
    const { getString } = useSystemSettings();
    const footer = publicSite.footer;
    const portalUrl = publicSite.portalUrl ?? null;
    const paragraphs = (about?.body ?? '').split(/\n\s*\n/).map((part) => part.trim()).filter(Boolean);
    // As the footer: the landing manager's contact details, else the system's support ones.
    const phone = footer?.contact_phone || getString('general.support_phone');
    const email = footer?.contact_email || getString('general.support_email');

    const contactRows: Array<{ key: string; label: string; value: ReactNode }> = [
        { key: 'address', label: text('contact.address'), value: footer?.contact_address },
        { key: 'phone', label: text('contact.phone'), value: phone ? <a href={`tel:${phone.replace(/\s+/g, '')}`} className="hover:underline">{phone}</a> : null },
        { key: 'email', label: text('contact.email'), value: email ? <a href={`mailto:${email}`} className="hover:underline">{email}</a> : null },
        { key: 'hours', label: text('contact.hours'), value: footer?.office_hours },
    ];

    return (
        <PublicLayout title={courtName} description={sections.about.subtitle || t('cm.home.meta.description')} publicSite={publicSite}>
            <Hero slides={slides} today={today} hearings={hearings} officeHours={footer?.office_hours ?? null} />

            {sections.services.visible && services.length > 0 && (
                <section id="services" aria-label={sections.services.title} className="relative z-10 -mt-14 scroll-mt-32 pb-4">
                    <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                        <ul className={cx('grid gap-4 sm:grid-cols-2', services.length >= 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3')}>
                            {services.map((service, index) => {
                                const body = (
                                    <>
                                        <span className="court-service-icon flex h-11 w-11 items-center justify-center rounded-lg bg-(--court-parchment) text-(--court-navy) ring-1 ring-(--court-line) dark:text-(--court-brass-soft)">
                                            <Icon path={SERVICE_ICONS[service.icon] ?? SERVICE_ICONS.document} />
                                        </span>
                                        <span className="mt-4 block text-base font-semibold text-(--court-ink) dark:text-white">{service.title}</span>
                                        {service.description && <span className="mt-2 block text-sm leading-relaxed text-(--court-muted)">{service.description}</span>}
                                        {service.features.length > 0 && (
                                            <span className="mt-3 block space-y-1">
                                                {service.features.map((feature, i) => <span key={i} className="block text-xs text-(--court-text)">— {feature}</span>)}
                                            </span>
                                        )}
                                        {service.meta && <span className="mt-4 block text-xs font-semibold uppercase tracking-[0.12em] text-(--court-brass-strong)">{service.meta}{service.href ? ' ›' : ''}</span>}
                                    </>
                                );
                                return (
                                    <li key={`${service.title}-${index}`} data-reveal style={stagger(index + 2)} className="flex">
                                        {service.href
                                            ? <a href={service.href} className="court-card court-service group flex w-full flex-col p-6 shadow-[0_20px_45px_-34px_rgba(12,26,49,0.7)]">{body}</a>
                                            : <div className="court-card court-service group flex w-full flex-col p-6 shadow-[0_20px_45px_-34px_rgba(12,26,49,0.7)]">{body}</div>}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </section>
            )}

            {about && (
                <Section id="about" eyebrow={text('eyebrow.about')} heading={sections.about}>
                    <div className="grid gap-10 lg:grid-cols-[1.5fr_1fr] lg:gap-16">
                        <div className="court-prose max-w-3xl text-base leading-relaxed text-(--court-text)">
                            {paragraphs.map((paragraph, index) => (
                                <p key={index} data-reveal style={stagger(index)} className={cx('whitespace-pre-line', index === 0 && 'text-lg text-(--court-ink) dark:text-white')}>{paragraph}</p>
                            ))}
                        </div>
                        {about.facts.length > 0 && (
                            <aside aria-labelledby="about-facts" data-reveal style={stagger(2)} className="court-card self-start p-6 sm:p-8">
                                <h3 id="about-facts" className="text-xs font-semibold uppercase tracking-[0.16em] text-(--court-brass-strong)">{text('about.facts')}</h3>
                                <dl className="mt-5 divide-y divide-(--court-line)">
                                    {about.facts.map((fact, index) => (
                                        <div key={index} className="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)] gap-4 py-3.5 text-sm">
                                            <dt className="text-(--court-muted)">{fact.label}</dt>
                                            <dd className="font-medium text-(--court-ink) dark:text-white">{fact.value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </aside>
                        )}
                    </div>
                </Section>
            )}

            {sections.members.visible && members.length > 0 && (
                <Section id="members" eyebrow={text('eyebrow.members')} heading={sections.members} tone="parchment" align="center">
                    <ul className={cx('mx-auto grid gap-5 sm:grid-cols-2', members.length >= 4 ? 'lg:grid-cols-4' : 'max-w-4xl lg:grid-cols-3')}>
                        {members.map((member, index) => <MemberCard key={`${member.name}-${index}`} member={member} index={index} />)}
                    </ul>
                </Section>
            )}

            {sections.hearings.visible && (
                <Section id="hearings" eyebrow={text('eyebrow.hearings')} heading={sections.hearings}>
                    <HearingList hearings={hearings} today={today.date} />
                </Section>
            )}

            {sections.metrics.visible && metrics.length > 0 && (
                <Section id="statistics" eyebrow={text('eyebrow.metrics')} heading={sections.metrics} tone="ink">
                    <dl className="grid gap-px overflow-hidden rounded-xl bg-white/10 sm:grid-cols-2 lg:grid-cols-3">
                        {metrics.map((metric, index) => (
                            <div key={metric.key} data-reveal style={stagger(index)} className="court-metric bg-(--court-ink) p-6 sm:p-8">
                                <dt className="text-sm font-medium text-slate-300">{metric.label}</dt>
                                <dd className="mt-3 text-4xl font-semibold tracking-tight text-(--court-brass-soft) tabular-nums">
                                    <CountUp value={metric.value} format={formatNumber} />
                                </dd>
                                <dd aria-hidden="true" className="court-metric-rule mt-4 h-0.5 w-12 rounded-full bg-(--court-brass)" />
                                {metric.description && <dd className="mt-2 text-xs leading-relaxed text-slate-400">{metric.description}</dd>}
                            </div>
                        ))}
                    </dl>
                </Section>
            )}

            {sections.process.visible && steps.length > 0 && (
                <Section id="process" eyebrow={text('eyebrow.process')} heading={sections.process} tone="parchment">
                    {/* A case's path: the line draws across, a light travels it, and each step lights as it passes. */}
                    <ol data-reveal="path" style={{ '--steps': steps.length } as CSSProperties} className={cx('court-path relative grid gap-8 sm:grid-cols-2', steps.length >= 5 ? 'lg:grid-cols-5' : steps.length === 4 ? 'lg:grid-cols-4' : 'lg:grid-cols-3')}>
                        <span aria-hidden="true" className="court-path-line absolute inset-x-8 top-6 hidden h-px bg-(--court-brass)/50 lg:block">
                            <span className="court-path-light" />
                        </span>
                        {steps.map((step, index) => (
                            <li key={`${step.title}-${index}`} className="court-proc-step relative" style={stagger(index)}>
                                <span className="court-proc-number relative flex h-12 w-12 items-center justify-center rounded-full border-2 border-(--court-brass) bg-(--court-parchment) text-base font-semibold text-(--court-ink) dark:text-white">
                                    {index + 1}
                                </span>
                                <h3 className="mt-5 text-base font-semibold text-(--court-ink) dark:text-white">{step.title}</h3>
                                {step.description && <p className="mt-2 text-sm leading-relaxed text-(--court-muted)">{step.description}</p>}
                                {(step.meta || step.duration) && (
                                    <p className="mt-3 text-xs font-semibold uppercase tracking-[0.1em] text-(--court-brass-strong)">{[step.meta, step.duration].filter(Boolean).join(' · ')}</p>
                                )}
                            </li>
                        ))}
                    </ol>
                </Section>
            )}

            {sections.news.visible && (
                <Section id="news" eyebrow={text('eyebrow.news')} heading={sections.news} aside={news.length > 0 ? <MoreLink href={route('court.public.news')}>{text('news.all')}</MoreLink> : undefined}>
                    {news.length === 0 ? (
                        <p className="court-card px-6 py-10 text-center text-sm text-(--court-muted)">{text('news.none')}</p>
                    ) : (
                        <ul className="grid gap-5 md:grid-cols-3">
                            {news.map((item, index) => (
                                <li key={item.id} data-reveal style={stagger(index)} className="flex">
                                    <a href={route('court.public.news.show', item.id)} className="court-card group flex w-full flex-col p-6">
                                        <LocalizedDateDisplay value={item.created_at} className="text-xs font-semibold uppercase tracking-[0.12em] text-(--court-brass-strong)" />
                                        <span className="mt-3 block text-lg font-semibold leading-snug text-(--court-ink) dark:text-white">{item.title}</span>
                                        {item.excerpt && <span className="mt-3 block line-clamp-3 text-sm leading-relaxed text-(--court-muted)">{item.excerpt}</span>}
                                        <span className="mt-auto pt-5 text-sm font-semibold text-(--court-navy) dark:text-(--court-brass-soft)">{text('news.read')} <span aria-hidden="true" className="court-arrow">›</span></span>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    )}
                </Section>
            )}

            {sections.resources.visible && resources.length > 0 && (
                <Section id="resources" eyebrow={text('eyebrow.resources')} heading={sections.resources} tone="parchment" aside={hasMoreResources ? <MoreLink href={route('court.public.resources')}>{text('resources.all')}</MoreLink> : undefined}>
                    <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                        {resources.map((resource, index) => (
                            <div key={resource.id} data-reveal style={stagger(index)} className="flex min-w-0 *:w-full">
                                <CourtResourceCard resource={resource} />
                            </div>
                        ))}
                    </div>
                </Section>
            )}

            {sections.faq.visible && faqs.length > 0 && (
                <Section id="faq" eyebrow={text('eyebrow.faq')} heading={sections.faq} aside={contactVisible ? <MoreLink href="#contact">{text('faq.more')}</MoreLink> : undefined}>
                    <div className="max-w-4xl">
                        <Faq faqs={faqs} />
                    </div>
                </Section>
            )}

            {contactVisible && (
                <Section id="contact" eyebrow={text('eyebrow.contact')} heading={sections.contact} tone="parchment">
                    <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
                        <div data-reveal className="court-card p-6 sm:p-8">
                            <dl className="grid gap-6 sm:grid-cols-2">
                                {contactRows.map((row) => (
                                    <div key={row.key}>
                                        <dt className="text-xs font-semibold uppercase tracking-[0.14em] text-(--court-brass-strong)">{row.label}</dt>
                                        <dd className={cx('mt-2 text-sm leading-relaxed', row.value ? 'text-(--court-ink) dark:text-white' : 'text-(--court-muted)')}>{row.value || text('contact.notPublished')}</dd>
                                    </div>
                                ))}
                            </dl>
                            {footer?.map_url && (
                                <a href={footer.map_url} target="_blank" rel="noopener noreferrer" className="mt-6 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-(--court-navy) hover:underline dark:text-(--court-brass-soft)">
                                    {text('contact.map')} <span aria-hidden="true">↗</span>
                                </a>
                            )}
                        </div>
                        <div data-reveal style={stagger(1)} className="court-ink-card relative isolate flex flex-col justify-between overflow-hidden rounded-xl bg-(--court-ink) p-6 text-white sm:p-8">
                            <div>
                                <p className="court-eyebrow court-eyebrow-light">{text('contact.partiesTitle')}</p>
                                <p className="mt-4 text-base leading-relaxed text-slate-200">{text('contact.partiesText')}</p>
                            </div>
                            {portalUrl && (
                                <a href={portalUrl} className="group mt-6 inline-flex min-h-12 items-center justify-center gap-2 rounded-md bg-(--court-brass) px-6 text-sm font-semibold text-(--court-ink) hover:bg-(--court-brass-soft)">
                                    {text('contact.partiesAction')} <span aria-hidden="true" className="court-arrow">›</span>
                                </a>
                            )}
                        </div>
                    </div>
                </Section>
            )}
        </PublicLayout>
    );
}
