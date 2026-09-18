import { useEffect, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import { useSystemSettings } from '@/hooks/useSystemSettings';
import PublicLayout from '@/Layouts/PublicLayout';
import {
    Building2,
    Users,
    CreditCard,
    Store,
    Layers,
    ScrollText,
    LayoutDashboard,
    CheckCircle,
    ChevronRight,
} from '@/Components/Icons';
import type { PageProps } from '@/types';
import { SVGProps } from 'react';

type IconProps = SVGProps<SVGSVGElement>;

function BarChart2Icon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <line x1="18" y1="20" x2="18" y2="10" />
            <line x1="12" y1="20" x2="12" y2="4" />
            <line x1="6" y1="20" x2="6" y2="14" />
        </svg>
    );
}

function ArrowRightIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <line x1="5" y1="12" x2="19" y2="12" />
            <polyline points="12 5 19 12 12 19" />
        </svg>
    );
}

function QrCodeIcon(p: IconProps) {
    return (
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...p}>
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <rect x="5" y="5" width="3" height="3" rx="0.5" fill="currentColor" stroke="none" />
            <rect x="16" y="5" width="3" height="3" rx="0.5" fill="currentColor" stroke="none" />
            <rect x="16" y="16" width="3" height="3" rx="0.5" fill="currentColor" stroke="none" />
            <rect x="5" y="16" width="3" height="3" rx="0.5" fill="currentColor" stroke="none" />
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
        const obs = new IntersectionObserver(
            ([e]) => { if (e.isIntersecting) { setInView(true); obs.disconnect(); } },
            { threshold },
        );
        obs.observe(el);
        return () => obs.disconnect();
    }, [threshold]);
    return { ref, inView };
}

const MODULE_ICON_MAP = [Building2, Users, CreditCard, Store, Layers, Users, ScrollText, BarChart2Icon];
const MODULE_KEY_LIST = ['module1', 'module2', 'module3', 'module4', 'module5', 'module6', 'module7', 'module8'] as const;
const TRUST_ICON_MAP = [QrCodeIcon, UserShieldIcon, ScrollText, LockIcon];
const TRUST_KEY_LIST = ['trust1', 'trust2', 'trust3', 'trust4'] as const;

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
const TRUST_TONES:  Tone[] = ['primary', 'success', 'warning', 'neutral'];
const MODULE_TONES: Tone[] = ['primary', 'success', 'warning', 'neutral', 'primary', 'success', 'neutral', 'warning'];
const STEP_COUNT = 7;

interface WelcomePageProps extends PageProps {
    auth: { user: { id: number; name: string; email: string } | null };
}

export default function Welcome() {
    const { auth } = usePage<WelcomePageProps>().props;
    const isAuthenticated = Boolean(auth?.user);
    const { t } = useLocale();
    const { getString } = useSystemSettings();
    const { ref: trustRef,   inView: trustInView   } = useInView();
    const { ref: modulesRef, inView: modulesInView } = useInView();
    const { ref: stepsRef,   inView: stepsInView   } = useInView();

    const appNameEn = getString('app.short_name', getString('app.name', 'AA Employee ID'));
    const steps = Array.from({ length: STEP_COUNT }, (_, i) => i + 1);

    return (
        <PublicLayout title={appNameEn}>
            {/* ─────────────────────────── HERO ────────────────────────────── */}
            <section
                aria-labelledby="hero-heading"
                className="relative overflow-hidden bg-gradient-to-br from-blue-700 via-blue-600 to-blue-800 py-20 sm:py-28 dark:from-blue-900 dark:via-blue-800 dark:to-slate-900"
            >
                <div
                    className="pointer-events-none absolute inset-0 opacity-[0.04]"
                    style={{
                        backgroundImage: 'linear-gradient(to right, white 1px, transparent 1px), linear-gradient(to bottom, white 1px, transparent 1px)',
                        backgroundSize: '48px 48px',
                    }}
                    aria-hidden="true"
                />
                <div className="pointer-events-none absolute -top-24 right-0 h-96 w-96 rounded-full bg-white/10 blur-3xl animate-orb-drift" aria-hidden="true" />

                <div className="relative mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="grid items-center gap-12 lg:grid-cols-2">
                        <div className="text-center lg:text-left">
                            <span className="inline-block rounded-full bg-orange-500/20 border border-orange-400/30 px-3 py-1 text-xs font-semibold text-orange-200 animate-fade-up">
                                {t('home.headerTagline')}
                            </span>
                            <h1
                                id="hero-heading"
                                className="mt-4 text-3xl font-extrabold leading-tight tracking-tight text-white sm:text-4xl xl:text-5xl animate-fade-up"
                                style={{ animationDelay: '0.12s' }}
                            >
                                {t('home.heroTitle')}
                            </h1>
                            <p className="mt-5 text-lg text-blue-100 sm:text-xl animate-fade-up" style={{ animationDelay: '0.24s' }}>
                                {t('home.heroSubtitle')}
                            </p>
                            <div className="mt-8 flex flex-col items-center gap-3 sm:flex-row sm:justify-center lg:justify-start animate-fade-up" style={{ animationDelay: '0.36s' }}>
                                {isAuthenticated ? (
                                    <Link
                                        href={route('dashboard')}
                                        className="inline-flex items-center gap-2 rounded-card bg-white px-6 py-3 text-sm font-bold text-blue-700 shadow-lg transition-all hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                    >
                                        <LayoutDashboard className="h-4 w-4" aria-hidden="true" />
                                        {t('home.heroCtaDashboard')}
                                    </Link>
                                ) : (
                                    <Link
                                        href={route('login')}
                                        className="inline-flex items-center gap-2 rounded-card bg-white px-6 py-3 text-sm font-bold text-blue-700 shadow-lg transition-all hover:bg-blue-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                    >
                                        {t('home.heroCtaLogin')}
                                        <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                                    </Link>
                                )}
                                <Link
                                    href="/announcements"
                                    className="inline-flex items-center gap-2 rounded-card border border-white/30 bg-white/10 px-6 py-3 text-sm font-semibold text-white backdrop-blur-sm transition-all hover:bg-white/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white"
                                >
                                    {t('nav.announcements')}
                                    <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                                </Link>
                            </div>
                        </div>

                        {/* Platform module icon grid */}
                        <div
                            className="mx-auto w-full max-w-sm lg:max-w-none"
                            aria-label={t('home.platformOverview')}
                            style={{ animation: 'fade-in-right 0.7s ease-out 0.2s both, float-y 5s ease-in-out 0.9s infinite' }}
                        >
                            <div className="rounded-panel border border-white/20 bg-white/10 p-5 shadow-2xl backdrop-blur-sm">
                                <p className="mb-4 text-center text-xs font-semibold text-blue-200">
                                    {t('home.platformOverview')}
                                </p>
                                <div className="grid grid-cols-4 gap-3">
                                    {MODULE_KEY_LIST.map((key, idx) => {
                                        const Icon = MODULE_ICON_MAP[idx];
                                        return (
                                            <div key={key} className="flex flex-col items-center gap-1.5 rounded-card bg-white/10 p-3 text-center">
                                                <Icon className="h-6 w-6 text-white/90" aria-hidden="true" />
                                                <span className="text-[9px] font-medium leading-tight text-blue-100">{t(`home.${key}Title`)}</span>
                                            </div>
                                        );
                                    })}
                                </div>
                                <div className="mt-4 flex items-center justify-between rounded-lg bg-white/10 px-3 py-2">
                                    <span className="text-xs text-blue-200">System Status</span>
                                    <span className="flex items-center gap-1.5 text-xs font-semibold text-green-300">
                                        <span className="relative flex h-2 w-2" aria-hidden="true">
                                            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-400 opacity-75" />
                                            <span className="relative inline-flex h-2 w-2 rounded-full bg-green-400" />
                                        </span>
                                        Operational
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            {/* ──────────────────── TRUST / SECURITY HIGHLIGHTS ─────────────── */}
            <section aria-labelledby="trust-heading" className="bg-gray-50 py-16 sm:py-20 dark:bg-slate-900">
                <div ref={trustRef} className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-12 text-center">
                        <h2
                            id="trust-heading"
                            className="text-2xl font-bold text-gray-900 sm:text-3xl dark:text-slate-100"
                            style={trustInView ? { animation: 'fade-up 0.65s ease-out both' } : { opacity: 0 }}
                        >
                            {t('home.trustSectionTitle')}
                        </h2>
                        <p
                            className="mt-3 text-base text-gray-500 dark:text-slate-400"
                            style={trustInView ? { animation: 'fade-up 0.65s ease-out 0.1s both' } : { opacity: 0 }}
                        >
                            {t('home.trustSectionSubtitle')}
                        </p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {TRUST_KEY_LIST.map((key, idx) => {
                            const Icon = TRUST_ICON_MAP[idx];
                            const tone = TRUST_TONES[idx];
                            return (
                                <div
                                    key={key}
                                    className="group relative overflow-hidden rounded-panel border border-gray-200/80 bg-white/95 p-5 shadow-[0_18px_45px_-28px_rgba(15,23,42,0.45)] transition duration-200 hover:-translate-y-0.5 hover:border-gray-300 hover:shadow-[0_24px_55px_-30px_rgba(15,23,42,0.65)] dark:border-slate-800/80 dark:bg-slate-900/95 dark:hover:border-slate-700"
                                    style={trustInView ? { animation: `fade-up 0.6s ease-out ${0.15 + idx * 0.1}s both` } : { opacity: 0 }}
                                >
                                    <div className="absolute left-0 top-0 h-1 w-1/2 bg-gradient-to-r from-[var(--color-primary)] via-[color:var(--color-primary)]/45 to-transparent" style={{ borderTopLeftRadius: 'inherit' }} />
                                    <div className={`pointer-events-none absolute -right-10 -top-12 h-28 w-28 rounded-full blur-3xl ${TONE_GLOW[tone]}`} />
                                    <div className="relative flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium text-gray-500 dark:text-slate-400">{t(`home.${key}Title`)}</p>
                                            <p className="mt-3 text-xs leading-relaxed text-gray-600 dark:text-slate-300">{t(`home.${key}Desc`)}</p>
                                        </div>
                                        <div className={`inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-panel ring-1 shadow-sm transition duration-200 group-hover:scale-105 ${TONE_ICON[tone]}`}>
                                            <Icon className="h-5 w-5" aria-hidden="true" />
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

            {/* ─────────────────────── PLATFORM MODULES ────────────────────── */}
            <section aria-labelledby="modules-heading" className="bg-white py-16 sm:py-20 dark:bg-slate-950">
                <div ref={modulesRef} className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div className="mb-12 text-center">
                        <h2
                            id="modules-heading"
                            className="text-2xl font-bold text-gray-900 sm:text-3xl dark:text-slate-100"
                            style={modulesInView ? { animation: 'fade-up 0.65s ease-out both' } : { opacity: 0 }}
                        >
                            {t('home.modulesSectionTitle')}
                        </h2>
                        <p
                            className="mt-3 text-base text-gray-500 dark:text-slate-400"
                            style={modulesInView ? { animation: 'fade-up 0.65s ease-out 0.1s both' } : { opacity: 0 }}
                        >
                            {t('home.modulesSectionSubtitle')}
                        </p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {MODULE_KEY_LIST.map((key, idx) => {
                            const Icon = MODULE_ICON_MAP[idx];
                            const tone = MODULE_TONES[idx];
                            return (
                                <div
                                    key={key}
                                    className="group relative overflow-hidden rounded-panel border border-gray-200/80 bg-white/95 p-5 shadow-[0_18px_45px_-28px_rgba(15,23,42,0.45)] transition duration-200 hover:-translate-y-0.5 hover:border-gray-300 hover:shadow-[0_24px_55px_-30px_rgba(15,23,42,0.65)] dark:border-slate-800/80 dark:bg-slate-900/95 dark:hover:border-slate-700"
                                    style={modulesInView ? { animation: `fade-up 0.6s ease-out ${0.1 + idx * 0.08}s both` } : { opacity: 0 }}
                                >
                                    <div className="absolute left-0 top-0 h-1 w-1/2 bg-gradient-to-r from-[var(--color-primary)] via-[color:var(--color-primary)]/45 to-transparent" style={{ borderTopLeftRadius: 'inherit' }} />
                                    <div className={`pointer-events-none absolute -right-10 -top-12 h-28 w-28 rounded-full blur-3xl ${TONE_GLOW[tone]}`} />
                                    <div className="relative flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-gray-900 dark:text-slate-100">{t(`home.${key}Title`)}</p>
                                            <p className="mt-2 text-xs leading-relaxed text-gray-500 dark:text-slate-400">{t(`home.${key}Desc`)}</p>
                                        </div>
                                        <div className={`inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-panel ring-1 shadow-sm transition duration-200 group-hover:scale-105 ${TONE_ICON[tone]}`}>
                                            <Icon className="h-5 w-5" aria-hidden="true" />
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            </section>

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
                            <ol className="relative grid grid-cols-7 gap-3">
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
                            <Link
                                href={route('dashboard')}
                                className="inline-flex items-center gap-2 rounded-card bg-[color:var(--color-primary)] px-6 py-3 text-sm font-bold text-white shadow-md transition-colors hover:bg-[color:var(--color-primary-hover)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:bg-blue-500 dark:hover:bg-[color:var(--color-primary-hover)]"
                            >
                                <LayoutDashboard className="h-4 w-4" aria-hidden="true" />
                                {t('home.heroCtaDashboard')}
                            </Link>
                        ) : (
                            <Link
                                href={route('login')}
                                className="inline-flex items-center gap-2 rounded-card bg-[color:var(--color-primary)] px-6 py-3 text-sm font-bold text-white shadow-md transition-colors hover:bg-[color:var(--color-primary-hover)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--color-primary)] dark:bg-blue-500 dark:hover:bg-[color:var(--color-primary-hover)]"
                            >
                                {t('home.heroCtaLogin')}
                                <ArrowRightIcon className="h-4 w-4" aria-hidden="true" />
                            </Link>
                        )}
                    </div>
                </div>
            </section>
        </PublicLayout>
    );
}
