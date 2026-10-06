import { useEffect, useRef, useState, type ComponentType } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import PublicLayout, { type PublicPageMeta } from '@/Layouts/PublicLayout';
import { publicCardClass } from '@/Components/public/PublicPage';
import {
    ActivityIcon,
    ArrowLeftRightIcon,
    BadgeCheckIcon,
    BoxesIcon,
    Briefcase,
    Building2,
    ChartLineIcon,
    ChevronRight,
    ClipboardCheckIcon,
    ClipboardListIcon,
    CreditCard,
    HandshakeIcon,
    InfoIcon,
    LayoutDashboard,
    NetworkIcon,
    QrCodeIcon,
    ScaleIcon,
    ScrollText,
    ShieldCheck,
    StarIcon,
    Store,
    TrendingUpIcon,
    UserIcon,
    Users,
} from '@/Components/Icons';
import type { PageProps } from '@/types';
import { SVGProps } from 'react';
import './Welcome.css';

type IconProps = SVGProps<SVGSVGElement>;
type Icon = ComponentType<IconProps>;

function ArrowRightIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <line x1="5" y1="12" x2="19" y2="12" />
            <polyline points="12 5 19 12 12 19" />
        </svg>
    );
}

function UserShieldIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
            <path d="M12 8a2 2 0 100 4 2 2 0 000-4z" />
            <path d="M8 18c0-2.2 1.8-4 4-4s4 1.8 4 4" />
        </svg>
    );
}

function LockIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" />
            <path d="M7 11V7a5 5 0 0110 0v4" />
        </svg>
    );
}

function useInView(threshold = 0.12) {
    const ref = useRef<HTMLDivElement>(null);
    const [inView, setInView] = useState(false);
    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        if (!('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            setInView(true);
            return;
        }
        const obs = new IntersectionObserver(
            ([e]) => { if (e.isIntersecting) { setInView(true); obs.disconnect(); } },
            { threshold },
        );
        obs.observe(el);
        return () => obs.disconnect();
    }, [threshold]);
    return { ref, inView };
}

type Tone = 'primary' | 'success' | 'warning' | 'neutral';
const TONE_ICON: Record<Tone, string> = {
    primary: 'bg-[color:var(--color-primary)]/10 text-[color:var(--color-primary)] ring-[color:var(--color-primary)]/15',
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-300 dark:ring-emerald-900/50',
    warning: 'bg-[color:var(--color-accent)]/10 text-[color:var(--color-accent)] ring-[color:var(--color-accent)]/15',
    neutral: 'bg-slate-100 text-slate-700 ring-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700',
};
const TONE_GLOW: Record<Tone, string> = {
    primary: 'bg-[color:var(--color-primary)]/10',
    success: 'bg-emerald-500/10',
    warning: 'bg-[color:var(--color-accent)]/10',
    neutral: 'bg-slate-500/10',
};

/**
 * One card: `key` names the translation pair `home.{key}Title` / `home.{key}Desc`.
 * `status` marks a service as available today or a planned integration, so
 * nothing planned reads as operational.
 */
type Card = { key: string; icon: Icon; tone: Tone; status?: 'available' | 'planned' };

/** Short labels in the hero's platform overview panel. */
const PANEL_TILES: { key: string; icon: Icon }[] = [
    { key: 'tileInstitutions', icon: Building2 },
    { key: 'tilePositions', icon: Briefcase },
    { key: 'tileEmployees', icon: Users },
    { key: 'tileIdCards', icon: CreditCard },
    { key: 'tileServices', icon: Store },
    { key: 'tilePerformance', icon: TrendingUpIcon },
    { key: 'tileGrievances', icon: ScaleIcon },
    { key: 'tileReports', icon: ChartLineIcon },
];

const MANAGE_CARDS: Card[] = [
    { key: 'manageOrg', icon: Building2, tone: 'primary' },
    { key: 'managePositions', icon: Briefcase, tone: 'success' },
    { key: 'manageRegistry', icon: Users, tone: 'warning' },
    { key: 'manageInsight', icon: ChartLineIcon, tone: 'neutral' },
];

const SERVICE_CARDS: Card[] = [
    { key: 'serviceCard', icon: CreditCard, tone: 'primary', status: 'available' },
    { key: 'serviceCafeteria', icon: Store, tone: 'success', status: 'available' },
    { key: 'serviceTransport', icon: ArrowLeftRightIcon, tone: 'warning', status: 'available' },
    { key: 'serviceProviders', icon: HandshakeIcon, tone: 'neutral', status: 'available' },
    { key: 'serviceIntegration', icon: NetworkIcon, tone: 'primary', status: 'available' },
    { key: 'serviceConsumer', icon: BoxesIcon, tone: 'neutral', status: 'planned' },
    { key: 'serviceHealth', icon: ActivityIcon, tone: 'neutral', status: 'planned' },
    { key: 'serviceWelfare', icon: BadgeCheckIcon, tone: 'neutral', status: 'planned' },
];

const WORK_CARDS: Card[] = [
    { key: 'workDaily', icon: ClipboardListIcon, tone: 'primary' },
    { key: 'workPerformance', icon: TrendingUpIcon, tone: 'success' },
    { key: 'workCompetency', icon: ClipboardCheckIcon, tone: 'warning' },
    { key: 'workFeedback', icon: StarIcon, tone: 'primary' },
    { key: 'workGrievance', icon: ScaleIcon, tone: 'neutral' },
    { key: 'workPortal', icon: UserIcon, tone: 'success' },
];

const TRUST_CARDS: Card[] = [
    { key: 'trust1', icon: UserShieldIcon, tone: 'primary' },
    { key: 'trust2', icon: LockIcon, tone: 'success' },
    { key: 'trust3', icon: ScrollText, tone: 'warning' },
    { key: 'trust4', icon: ShieldCheck, tone: 'neutral' },
];

const STEP_COUNT = 8;
// The rest of the questions are on the Support page.
const FAQ_COUNT = 5;

const primaryCtaCls = 'inline-flex items-center gap-2 rounded-card bg-[color:var(--color-primary)] px-6 py-3 text-sm font-bold text-white shadow-md transition-colors hover:bg-[color:var(--color-primary-hover)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:bg-blue-500 dark:hover:bg-[color:var(--color-primary-hover)]';
const heroPrimaryCls = 'inline-flex items-center gap-2 rounded-card bg-white px-6 py-3 text-sm font-bold text-blue-700 shadow-lg transition-all hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white';
const heroSecondaryCls = 'inline-flex items-center gap-2 rounded-card border border-white/30 bg-white/10 px-6 py-3 text-sm font-semibold text-white backdrop-blur-sm transition-all hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white';

interface WelcomePageProps extends PageProps {
    auth: { user: { id: number; name: string; email: string } | null };
    meta?: PublicPageMeta;
}

function SectionHeader({ id, title, subtitle, inView }: { id: string; title: string; subtitle: string; inView: boolean }) {
    return (
        <div className="mb-12 text-center">
            <h2
                id={id}
                className="text-2xl font-bold text-gray-900 sm:text-3xl dark:text-slate-100"
                style={inView ? { animation: 'fade-up 0.65s ease-out both' } : { opacity: 0 }}
            >
                {title}
            </h2>
            <p
                className="mt-3 text-base text-gray-500 dark:text-slate-400"
                style={inView ? { animation: 'fade-up 0.65s ease-out 0.1s both' } : { opacity: 0 }}
            >
                {subtitle}
            </p>
        </div>
    );
}

function InfoCard({ card, index, inView, muted = false }: { card: Card; index: number; inView: boolean; muted?: boolean }) {
    const { t } = useLocale();
    const Icon = card.icon;

    return (
        <div
            className="home-card group relative overflow-hidden rounded-panel border border-gray-200/80 bg-white/95 p-5 shadow-[0_18px_45px_-28px_rgba(15,23,42,0.45)] transition duration-200 hover:-translate-y-0.5 hover:border-gray-300 hover:shadow-[0_24px_55px_-30px_rgba(15,23,42,0.65)] dark:border-slate-800/80 dark:bg-slate-900/95 dark:hover:border-slate-700"
            style={inView ? { animation: `fade-up 0.6s ease-out ${0.1 + index * 0.08}s both` } : { opacity: 0 }}
        >
            <div className="absolute left-0 top-0 h-1 w-1/2 bg-gradient-to-r from-[var(--color-primary)] via-[color:var(--color-primary)]/45 to-transparent" style={{ borderTopLeftRadius: 'inherit' }} />
            <div className={`pointer-events-none absolute -right-10 -top-12 h-28 w-28 rounded-full blur-3xl ${TONE_GLOW[card.tone]}`} />
            <div className="relative flex items-start justify-between gap-3">
                <div className="min-w-0">
                    {muted ? (
                        <h3 className="text-[15px] font-medium text-gray-600 dark:text-slate-300">{t(`home.${card.key}Title`)}</h3>
                    ) : (
                        <h3 className="text-[15px] font-semibold text-gray-900 dark:text-slate-100">{t(`home.${card.key}Title`)}</h3>
                    )}
                    <p className={`${muted ? 'mt-3 text-gray-600 dark:text-slate-300' : 'mt-2 text-gray-600 dark:text-slate-400'} text-justify text-[13px] leading-relaxed`}>{t(`home.${card.key}Desc`)}</p>
                </div>
                <div className={`inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-panel ring-1 shadow-sm transition duration-200 group-hover:scale-105 ${TONE_ICON[card.tone]}`}>
                    <Icon className="h-5 w-5" aria-hidden="true" />
                </div>
            </div>
        </div>
    );
}

/** A titled grid of cards; the same markup the trust and module sections always used. */
function CardSection({ id, prefix, cards, tinted, columns = 4, muted = false }: {
    id: string; prefix: string; cards: Card[]; tinted: boolean; columns?: 3 | 4; muted?: boolean;
}) {
    const { t } = useLocale();
    const { ref, inView } = useInView();

    return (
        <section aria-labelledby={id} className={`${tinted ? 'bg-gray-50 dark:bg-slate-900' : 'bg-white dark:bg-slate-950'} py-16 sm:py-20`}>
            <div ref={ref} className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeader id={id} title={t(`home.${prefix}SectionTitle`)} subtitle={t(`home.${prefix}SectionSubtitle`)} inView={inView} />
                <div className={`grid gap-4 sm:grid-cols-2 ${columns === 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4'}`}>
                    {cards.map((card, idx) => <InfoCard key={card.key} card={card} index={idx} inView={inView} muted={muted} />)}
                </div>
            </div>
        </section>
    );
}

/**
 * Services split by status, so nothing planned reads as operational: available
 * ones in the standard card, planned integrations in a muted dashed card.
 */
function ServicesSection() {
    const { t } = useLocale();
    const { ref, inView } = useInView();
    const available = SERVICE_CARDS.filter((card) => card.status === 'available');
    const planned = SERVICE_CARDS.filter((card) => card.status === 'planned');

    return (
        <section aria-labelledby="services-heading" className="bg-white py-16 sm:py-20 dark:bg-slate-950">
            <div ref={ref} className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <SectionHeader id="services-heading" title={t('home.servicesSectionTitle')} subtitle={t('home.servicesSectionSubtitle')} inView={inView} />

                <h3 className="mb-4 flex items-center gap-2 text-sm font-bold text-[color:var(--color-primary)]">
                    <span aria-hidden="true" className="h-2 w-2 rounded-full bg-emerald-600" />
                    {t('home.statusAvailable')}
                </h3>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    {available.map((card, idx) => <InfoCard key={card.key} card={card} index={idx} inView={inView} />)}
                </div>

                <h3 className="mb-4 mt-10 flex items-center gap-2 text-sm font-bold text-gray-600 dark:text-slate-300">
                    <span aria-hidden="true" className="h-2 w-2 rounded-full border-2 border-slate-400" />
                    {t('home.statusPlanned')}
                </h3>
                <div className="grid gap-4 md:grid-cols-3">
                    {planned.map((card) => {
                        const Icon = card.icon;
                        return (
                            <div key={card.key} className="flex items-start gap-3.5 rounded-panel border border-dashed border-slate-300 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/60">
                                <div className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                                    <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
                                </div>
                                <div className="min-w-0">
                                    <h4 className="text-sm font-semibold text-slate-700 dark:text-slate-200">{t(`home.${card.key}Title`)}</h4>
                                    <p className="mt-1 text-justify text-[13px] leading-relaxed text-slate-500 dark:text-slate-400">{t(`home.${card.key}Desc`)}</p>
                                </div>
                            </div>
                        );
                    })}
                </div>

                <div role="note" className="mt-8 flex items-start gap-3 rounded-card border border-[color:var(--color-primary)]/15 bg-[color:var(--color-primary)]/5 px-5 py-4 text-sm leading-relaxed text-gray-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                    <InfoIcon className="mt-0.5 h-5 w-5 shrink-0 text-[color:var(--color-primary)]" aria-hidden="true" />
                    <p>{t('home.servicesNote')}</p>
                </div>
            </div>
        </section>
    );
}

export default function Welcome() {
    const { auth, meta } = usePage<WelcomePageProps>().props;
    const isAuthenticated = Boolean(auth?.user);
    const { t } = useLocale();
    const { getString, getBoolean } = useSystemSettings();
    const { ref: stepsRef, inView: stepsInView } = useInView();
    const { ref: verifyRef, inView: verifyInView } = useInView();

    const appNameEn = getString('app.short_name', getString('app.name', 'AA Employee ID'));
    const steps = Array.from({ length: STEP_COUNT }, (_, i) => i + 1);
    const faqs = Array.from({ length: FAQ_COUNT }, (_, i) => i + 1);

    return (
        <PublicLayout title={appNameEn} description={t('home.metaDescription')} meta={meta}>
            <div className="public-home" data-motion={getBoolean('appearance.enable_ui_animations', true) ? 'on' : 'off'}>
            {/* ─────────────────────────── HERO ────────────────────────────── */}
            <section
                aria-labelledby="hero-heading"
                className="home-hero relative overflow-hidden py-8 sm:py-16 lg:py-24"
            >
                <div
                    className="home-hero-grid pointer-events-none absolute inset-0 opacity-[0.06]"
                    style={{
                        backgroundImage: 'linear-gradient(to right, white 1px, transparent 1px), linear-gradient(to bottom, white 1px, transparent 1px)',
                        backgroundSize: '48px 48px',
                    }}
                    aria-hidden="true"
                />
                <div className="pointer-events-none absolute -top-24 right-0 h-96 w-96 rounded-full bg-white/10 blur-3xl animate-orb-drift" aria-hidden="true" />

                <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="grid min-w-0 grid-cols-1 items-center gap-8 sm:gap-12 lg:grid-cols-2">
                        <div className="min-w-0 text-center lg:text-left">
                            <span className="inline-block rounded-full bg-orange-500/20 border border-orange-400/30 px-3 py-1 text-xs font-semibold text-orange-200 animate-fade-up">
                                {t('home.headerTagline')}
                            </span>
                            <h1
                                id="hero-heading"
                                className="mt-4 text-[clamp(1.5rem,6vw,1.875rem)] font-extrabold leading-tight tracking-tight text-white sm:text-4xl xl:text-5xl animate-fade-up"
                                style={{ animationDelay: '0.12s' }}
                            >
                                {t('home.heroTitle')}
                            </h1>
                            <p className="mt-4 text-base leading-relaxed text-blue-100 sm:mt-5 sm:text-xl animate-fade-up" style={{ animationDelay: '0.24s' }}>
                                {t('home.heroSubtitle')}
                            </p>
                            <div className="mt-6 flex flex-col items-stretch gap-3 sm:mt-8 sm:flex-row sm:flex-wrap sm:justify-center lg:justify-start animate-fade-up [&>a]:min-h-12 [&>a]:justify-center" style={{ animationDelay: '0.36s' }}>
                                {isAuthenticated ? (
                                    <Link href={route('dashboard')} className={heroPrimaryCls}>
                                        <LayoutDashboard className="h-4 w-4" aria-hidden="true" />
                                        {t('home.heroCtaDashboard')}
                                    </Link>
                                ) : (
                                    <Link href={route('public.verify')} className={heroPrimaryCls}>
                                        {t('home.heroCtaVerify')}
                                        <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                                    </Link>
                                )}
                                {isAuthenticated ? (
                                    <Link href={route('public.verify')} className={heroSecondaryCls}>
                                        {t('home.heroCtaVerify')}
                                        <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                                    </Link>
                                ) : (
                                    <Link href={route('employee.login')} className={heroSecondaryCls}>
                                        {t('home.heroCtaPortal')}
                                        <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                                    </Link>
                                )}
                                <Link href={route('public.services')} className={heroSecondaryCls}>
                                    {t('home.heroCtaServices')}
                                    <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                                </Link>
                            </div>
                        </div>

                        {/* Platform module icon grid */}
                        <div
                            className="home-platform mx-auto w-full max-w-sm lg:max-w-none"
                            aria-label={t('home.platformOverview')}
                        >
                            <div className="home-platform-panel rounded-panel border border-white/20 bg-white/10 p-4 shadow-2xl backdrop-blur-sm sm:p-6">
                                <p className="mb-4 text-center text-xs font-semibold text-blue-200">
                                    {t('home.platformOverview')}
                                </p>
                                <div className="grid grid-cols-2 gap-3 min-[400px]:grid-cols-4 lg:grid-cols-4">
                                    {PANEL_TILES.map(({ key, icon: Icon }, idx) => (
                                        <div key={key} className="home-module-tile flex min-w-0 flex-col items-center gap-2 rounded-card bg-white/10 px-2 py-4 text-center" style={{ animationDelay: `${0.15 + idx * 0.06}s` }}>
                                            <Icon className="h-6 w-6 text-white/90" aria-hidden="true" />
                                            <span className="text-[11px] font-medium leading-snug text-blue-100">{t(`home.${key}`)}</span>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-4 flex items-center justify-between rounded-lg bg-white/10 px-3 py-2">
                                    <span className="text-xs text-blue-200">{t('home.statusLabel')}</span>
                                    <span className="flex items-center gap-1.5 text-xs font-semibold text-green-300">
                                        <span className="relative flex h-2 w-2" aria-hidden="true">
                                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75" />
                                            <span className="relative inline-flex h-2 w-2 rounded-full bg-green-400" />
                                        </span>
                                        {t('home.statusValue')}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* ───────────────────── WHAT EUISIS MANAGES ───────────────────── */}
            <CardSection id="manage-heading" prefix="manage" cards={MANAGE_CARDS} tinted />

            {/* ─────────────────── ONE ID — MULTIPLE SERVICES ──────────────── */}
            <ServicesSection />

            {/* ──────────── PERFORMANCE, FEEDBACK AND SELF-SERVICE ─────────── */}
            <CardSection id="work-heading" prefix="work" cards={WORK_CARDS} tinted columns={3} />

            {/* ──────────────── SECURITY, PRIVACY, ACCOUNTABILITY ──────────── */}
            <CardSection id="trust-heading" prefix="trust" cards={TRUST_CARDS} tinted={false} muted />

            {/* ──────────────────────── HOW IT WORKS ───────────────────────── */}
            <section aria-labelledby="how-it-works-heading" className="bg-gray-50 py-16 sm:py-20 dark:bg-slate-900">
                <div ref={stepsRef} className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-12 text-center">
                        <h2
                            id="how-it-works-heading"
                            className="text-2xl font-bold text-gray-900 sm:text-3xl dark:text-slate-100"
                            style={stepsInView ? { animation: 'fade-up 0.65s ease-out both' } : { opacity: 0 }}
                        >
                            {t('home.howItWorksSectionTitle')}
                        </h2>
                        <p className="mt-3 text-base text-gray-500 dark:text-slate-400">{t('home.howItWorksSectionSubtitle')}</p>
                    </div>

                    {/* Desktop stepper */}
                    <div className="hidden md:block">
                        <div className="relative">
                            <div className="absolute left-0 right-0 top-5 h-0.5 bg-blue-100 dark:bg-slate-700 overflow-hidden" aria-hidden="true">
                                <div
                                    className="h-full bg-blue-400 dark:bg-blue-500"
                                    style={stepsInView
                                        ? { animation: 'step-line-grow 1.4s ease-out 0.2s both', transformOrigin: 'left' }
                                        : { transform: 'scaleX(0)', transformOrigin: 'left' }}
                                />
                            </div>
                            <ol className="relative grid grid-cols-8 gap-3">
                                {steps.map((stepNum) => (
                                    <li key={stepNum} className="flex flex-col items-center text-center">
                                        <div
                                            className="relative z-10 flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 border-[color:var(--color-primary)] bg-white font-bold text-[color:var(--color-primary)] text-sm dark:border-blue-400 dark:bg-slate-900 dark:text-[color:var(--color-primary)]"
                                            style={stepsInView ? { animation: `pop-in 0.4s ease-out ${0.2 + stepNum * 0.12}s both` } : { opacity: 0 }}
                                        >
                                            {stepNum}
                                        </div>
                                        <h3
                                            className="mt-3 text-xs font-semibold text-gray-900 dark:text-slate-100"
                                            style={stepsInView ? { animation: `fade-up 0.5s ease-out ${0.3 + stepNum * 0.12}s both` } : { opacity: 0 }}
                                        >
                                            {t(`home.step${stepNum}Title`)}
                                        </h3>
                                        <p
                                            className="mt-1 text-[11px] leading-snug text-gray-500 dark:text-slate-400"
                                            style={stepsInView ? { animation: `fade-up 0.5s ease-out ${0.35 + stepNum * 0.12}s both` } : { opacity: 0 }}
                                        >
                                            {t(`home.step${stepNum}Desc`)}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </div>

                    {/* Mobile stepper */}
                    <ol className="space-y-4 md:hidden">
                        {steps.map((stepNum, idx) => (
                            <li
                                key={stepNum}
                                className="flex gap-4 rounded-card border border-gray-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-800"
                                style={stepsInView ? { animation: `fade-up 0.5s ease-out ${idx * 0.07}s both` } : { opacity: 0 }}
                            >
                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[color:var(--color-primary)] text-sm font-bold text-white dark:bg-blue-500">
                                    {stepNum}
                                </div>
                                <div className="min-w-0">
                                    <h3 className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t(`home.step${stepNum}Title`)}</h3>
                                    <p className="mt-0.5 text-xs text-gray-500 dark:text-slate-400">{t(`home.step${stepNum}Desc`)}</p>
                                </div>
                                {idx < steps.length - 1 && (
                                    <ChevronRight className="ml-auto h-5 w-5 shrink-0 text-gray-300 dark:text-slate-600" aria-hidden="true" />
                                )}
                            </li>
                        ))}
                    </ol>

                    <div className="mt-12 text-center">
                        {isAuthenticated ? (
                            <Link href={route('dashboard')} className={primaryCtaCls}>
                                <LayoutDashboard className="h-4 w-4" aria-hidden="true" />
                                {t('home.heroCtaDashboard')}
                            </Link>
                        ) : (
                            <Link href={route('login')} className={primaryCtaCls}>
                                {t('home.heroCtaLogin')}
                                <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                            </Link>
                        )}
                    </div>
                </div>
            </section>

            {/* ─────────────────────── VERIFY + FAQ ────────────────────────── */}
            <section aria-label={t('home.verifySectionTitle')} className="bg-white py-16 sm:py-20 dark:bg-slate-950">
                <div ref={verifyRef} className="mx-auto grid max-w-7xl items-start gap-10 px-4 sm:px-6 lg:grid-cols-12 lg:px-8">
                    <div
                        className="flex flex-col gap-4 rounded-panel bg-[color:var(--color-primary)] p-6 text-white sm:p-8 lg:col-span-5 dark:bg-[color:var(--color-primary-900)]"
                        style={verifyInView ? { animation: 'fade-up 0.65s ease-out both' } : { opacity: 0 }}
                    >
                        <QrCodeIcon className="h-8 w-8" aria-hidden="true" />
                        <h2 id="verify-heading" className="home-heading-start text-2xl font-bold">{t('home.verifySectionTitle')}</h2>
                        <p className="text-justify text-[15px] leading-relaxed text-blue-100">{t('home.verifySectionBody')}</p>
                        <Link href={route('public.verify')} className={`${heroPrimaryCls} mt-2 self-start`}>
                            {t('home.heroCtaVerify')}
                            <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                        </Link>
                    </div>

                    <div className="min-w-0 lg:col-span-7">
                    <h2 id="faq-heading" className="home-heading-start mb-6 text-2xl font-bold text-gray-900 dark:text-slate-100">{t('home.faqTitle')}</h2>
                    {/* Native <details>, as on the Support page: keyboard and screen-reader support built in. */}
                    <div className="space-y-3">
                        {faqs.map((n) => (
                            <details key={n} className={`${publicCardClass} group py-2`}>
                                <summary className="relative flex min-h-[44px] cursor-pointer list-none items-center justify-between gap-3 py-2 text-sm font-semibold text-gray-900 marker:hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:text-slate-100 [&::-webkit-details-marker]:hidden">
                                    <span className="[overflow-wrap:anywhere]">{t(`home.faq${n}Q`)}</span>
                                    <span aria-hidden="true" className="shrink-0 text-gray-400 transition-transform group-open:rotate-45">+</span>
                                </summary>
                                <p className="relative pb-2 text-sm text-gray-700 dark:text-slate-300">{t(`home.faq${n}A`)}</p>
                            </details>
                        ))}
                    </div>
                    <p className="mt-6 text-sm">
                        <Link href={route('public.support')} className="font-semibold text-[color:var(--color-primary)] hover:underline">{t('home.faqMore')} <span aria-hidden="true">→</span></Link>
                    </p>
                    </div>
                </div>
            </section>
            </div>
        </PublicLayout>
    );
}
