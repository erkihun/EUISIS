import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import UserAvatar from '@/Components/UserAvatar';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useLocale } from '@/hooks/useLocale';
import type { PageProps } from '@/types';

// ── Constants ─────────────────────────────────────────────────────────────────

const COUNTRY_CODES = [
    { code: '+251', label: '🇪🇹 +251' },
    { code: '+1', label: '🇺🇸 +1'   },
    { code: '+44', label: '🇬🇧 +44'  },
    { code: '+971', label: '🇦🇪 +971' },
    { code: '+966', label: '🇸🇦 +966' },
    { code: '+254', label: '🇰🇪 +254' },
    { code: '+255', label: '🇹🇿 +255' },
    { code: '+256', label: '🇺🇬 +256' },
    { code: '+20', label: '🇪🇬 +20'  },
    { code: '+27', label: '🇿🇦 +27'  },
    { code: '+49', label: '🇩🇪 +49'  },
    { code: '+33', label: '🇫🇷 +33'  },
    { code: '+86', label: '🇨🇳 +86'  },
    { code: '+91', label: '🇮🇳 +91'  },
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function parsePhone(phone: string | null): { country: string; local: string } {
    if (!phone) return { country: '+251', local: '' };
    const sorted = [...COUNTRY_CODES].sort((a, b) => b.code.length - a.code.length);
    for (const cc of sorted) {
        if (phone.startsWith(cc.code)) return { country: cc.code, local: phone.slice(cc.code.length) };
    }
    return { country: '+251', local: phone.replace(/^\+/, '') };
}

function formatNationalId(value: string): string {
    const d = value.replace(/\D/g, '').slice(0, 16);
    return d.replace(/(\d{4})(?=\d)/g, '$1 ');
}

// ── Types ─────────────────────────────────────────────────────────────────────

type Profile = {
    name: string; email: string; phone_number: string | null;
    gender: string | null; national_id: string | null;
    profile_photo_url: string | null; initials: string;
    roles: string[]; status: string; last_login_at: string | null;
};

// ── Sub-components ────────────────────────────────────────────────────────────

const inputCls = 'w-full rounded-card border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-900 placeholder-gray-400 transition focus:border-[color:var(--color-primary)] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[color:var(--color-primary)]/20 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-100 dark:placeholder-slate-500 dark:focus:border-blue-400 dark:focus:bg-slate-800';

function Field({ label, error, hint, children }: { label: string; error?: string; hint?: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1.5">
            <label className="block text-xs font-semibold text-gray-500 dark:text-slate-400">
                {label}
            </label>
            {children}
            {hint && !error && <p className="text-xs text-gray-400 dark:text-slate-500">{hint}</p>}
            {error && <p className="text-xs font-medium text-red-500 dark:text-red-400">{error}</p>}
        </div>
    );
}

function SectionHeader({ icon, title, subtitle }: { icon: React.ReactNode; title: string; subtitle?: string }) {
    return (
        <div className="flex items-start gap-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-card bg-blue-50 text-[color:var(--color-primary)] dark:bg-blue-950/40 dark:text-[color:var(--color-primary)]">
                {icon}
            </div>
            <div>
                <h2 className="text-base font-semibold text-gray-900 dark:text-slate-100">{title}</h2>
                {subtitle && <p className="mt-0.5 text-sm text-gray-500 dark:text-slate-400">{subtitle}</p>}
            </div>
        </div>
    );
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function Edit({
    mustVerifyEmail, status, profile,
}: PageProps<{ mustVerifyEmail: boolean; status?: string; profile: Profile }>) {
    const { t } = useLocale();
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);

    const parsedPhone = parsePhone(profile.phone_number);
    const [phoneCountry, setPhoneCountry] = useState(parsedPhone.country);
    const [phoneLocal, setPhoneLocal]     = useState(parsedPhone.local);
    const [nidDisplay, setNidDisplay]     = useState(formatNationalId(profile.national_id ?? ''));

    const form = useForm<{
        name: string; email: string; phone_number: string;
        gender: string; national_id: string; profile_photo: File | null;
    }>({
        name: profile.name,
        email: profile.email,
        phone_number: profile.phone_number ?? '',
        gender: profile.gender ?? '',
        national_id: (profile.national_id ?? '').replace(/\D/g, ''),
        profile_photo: null,
    });

    function handlePhoneCountry(code: string) {
        setPhoneCountry(code);
        form.setData('phone_number', phoneLocal ? code + phoneLocal : '');
    }
    function handlePhoneLocal(value: string) {
        const digits = value.replace(/\D/g, '').slice(0, 10);
        setPhoneLocal(digits);
        form.setData('phone_number', digits ? phoneCountry + digits : '');
    }
    function handleNationalId(value: string) {
        const digits = value.replace(/\D/g, '').slice(0, 16);
        setNidDisplay(formatNationalId(digits));
        form.setData('national_id', digits);
    }
    function handlePhotoChange(e: React.ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;
        form.setData('profile_photo', file);
        const reader = new FileReader();
        reader.onload = (ev) => setPhotoPreview(ev.target?.result as string);
        reader.readAsDataURL(file);
    }
    function submit(e: React.FormEvent) {
        e.preventDefault();
        const payload = new FormData();
        payload.append('_method', 'PATCH');
        payload.append('name', form.data.name);
        payload.append('email', form.data.email);
        payload.append('phone_number', form.data.phone_number);
        payload.append('gender', form.data.gender);
        payload.append('national_id', form.data.national_id);
        if (form.data.profile_photo) payload.append('profile_photo', form.data.profile_photo);
        router.post(route('profile.update'), payload, {
            preserveScroll: true,
            onSuccess: () => { form.setData('profile_photo', null); setPhotoPreview(null); },
        });
    }

    const currentPhoto = photoPreview ?? profile.profile_photo_url;
    const isActive     = profile.status === 'active';

    return (
        <AuthenticatedLayout>
            <Head title={t('profile.title')} />

            {/* ── Identity header ───────────────────────────────────────── */}
            <div className="mb-6 overflow-hidden rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div className="flex flex-col items-center gap-5 px-6 py-6 sm:flex-row sm:items-center">
                    {/* Avatar with camera button */}
                    <div className="group relative shrink-0">
                        <div className="h-20 w-20 overflow-hidden rounded-card ring-1 ring-gray-200 sm:h-24 sm:w-24 dark:ring-slate-700">
                            <UserAvatar src={currentPhoto} name={form.data.name || profile.name} size={112} />
                        </div>
                        <label className="absolute inset-0 flex cursor-pointer items-center justify-center rounded-card bg-black/0 transition group-hover:bg-black/40">
                            <span className="flex h-9 w-9 items-center justify-center rounded-full bg-white/0 text-white opacity-0 transition group-hover:bg-white/20 group-hover:opacity-100">
                                <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                            </span>
                            <input type="file" accept="image/jpg,image/jpeg,image/png,image/webp" className="sr-only" onChange={handlePhotoChange} />
                        </label>
                        {photoPreview && (
                            <button
                                type="button"
                                onClick={() => { form.setData('profile_photo', null); setPhotoPreview(null); }}
                                className="absolute -right-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full bg-red-500 text-white shadow-md hover:bg-red-600"
                                title="Remove"
                            >
                                <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        )}
                    </div>

                    {/* Name / email / roles */}
                    <div className="flex-1 text-center sm:text-left">
                        <h1 className="text-xl font-semibold text-gray-900 dark:text-slate-100">{profile.name}</h1>
                        <p className="mt-0.5 text-sm text-gray-500 dark:text-slate-400">{profile.email}</p>
                        <div className="mt-2.5 flex flex-wrap justify-center gap-1.5 sm:justify-start">
                            {profile.roles.map((role) => (
                                <span
                                    key={role}
                                    className="rounded-control bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                                >
                                    {role}
                                </span>
                            ))}
                        </div>
                    </div>

                    {/* Account status — same badge vocabulary as everywhere else. */}
                    <div className="shrink-0">
                        <StatusBadge status={isActive ? 'active' : 'inactive'} label={profile.status} />
                    </div>
                </div>

                {form.errors.profile_photo && (
                    <p className="px-6 pb-3 text-xs text-red-600 dark:text-red-400">{form.errors.profile_photo}</p>
                )}
            </div>

            {/* ── Main content ──────────────────────────────────────────── */}
            <div className="grid gap-6 lg:grid-cols-[260px_minmax(0,1fr)]">

                {/* ── Sidebar ─────────────────────────────────────────── */}
                <div className="space-y-4">
                    {/* Account info */}
                    <div className="rounded-panel border border-gray-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 className="mb-4 text-xs font-semibold text-gray-500 dark:text-slate-400">
                            {t('profile.accountSummary')}
                        </h3>
                        <ul className="space-y-3">
                            <li className="flex items-center justify-between gap-3 text-sm">
                                <span className="flex items-center gap-2 text-gray-500 dark:text-slate-400">
                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {t('profile.status')}
                                </span>
                                <StatusBadge status={isActive ? 'active' : 'inactive'} label={profile.status} />
                            </li>
                            <li className="flex items-start justify-between gap-3 text-sm">
                                <span className="flex items-center gap-2 text-gray-500 dark:text-slate-400">
                                    <svg className="h-4 w-4 shrink-0" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    {t('profile.lastLogin')}
                                </span>
                                <span className="text-right text-xs font-medium text-gray-700 dark:text-slate-300">
                                    {profile.last_login_at ?? t('profile.notAvailable')}
                                </span>
                            </li>
                        </ul>
                    </div>

                    {/* Security notice */}
                    <div className="rounded-panel border border-amber-200 bg-amber-50 p-5 dark:border-amber-800/40 dark:bg-amber-950/20">
                        <div className="flex gap-2.5">
                            <svg className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                            <div>
                                <p className="text-xs font-semibold text-amber-800 dark:text-amber-300">{t('profile.accountDeletionDisabled')}</p>
                                <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">{t('profile.selfDeleteProtected')}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* ── Right column ─────────────────────────────────────── */}
                <div className="space-y-6">

                    {/* Personal information form */}
                    <div className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-gray-100 px-6 py-5 dark:border-slate-800">
                            <SectionHeader
                                icon={
                                    <svg className="h-4.5 w-4.5" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                                    </svg>
                                }
                                title={t('profile.personalInformation')}
                                subtitle={t('profile.personalInformationHelp')}
                            />
                        </div>

                        <form onSubmit={submit} className="p-6">
                            <div className="grid gap-5 sm:grid-cols-2">
                                <Field label={t('profile.name')} error={form.errors.name}>
                                    <div className="relative">
                                        <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0" />
                                            </svg>
                                        </span>
                                        <input className={inputCls + ' pl-9'} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                    </div>
                                </Field>

                                <Field label={t('profile.email')} error={form.errors.email}>
                                    <div className="relative">
                                        <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                            </svg>
                                        </span>
                                        <input type="email" className={inputCls + ' pl-9'} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                                    </div>
                                </Field>

                                <Field label={t('profile.phoneNumber')} error={form.errors.phone_number}>
                                    <div className="flex overflow-hidden rounded-card border border-gray-200 bg-gray-50 focus-within:border-[color:var(--color-primary)] focus-within:ring-2 focus-within:ring-[color:var(--color-primary)]/20 dark:border-slate-700 dark:bg-slate-800/60">
                                        <select
                                            className="border-0 bg-transparent py-2.5 pl-3 pr-1 text-sm text-gray-700 focus:outline-none dark:text-slate-300"
                                            value={phoneCountry}
                                            onChange={(e) => handlePhoneCountry(e.target.value)}
                                        >
                                            {COUNTRY_CODES.map((cc) => (
                                                <option key={cc.code} value={cc.code}>{cc.label}</option>
                                            ))}
                                        </select>
                                        <div className="my-2 w-px bg-gray-200 dark:bg-slate-600" />
                                        <input
                                            className="flex-1 border-0 bg-transparent px-3 py-2.5 text-sm text-gray-900 placeholder-gray-400 focus:outline-none dark:text-slate-100 dark:placeholder-slate-500"
                                            placeholder="9xxxxxxxx"
                                            value={phoneLocal}
                                            onChange={(e) => handlePhoneLocal(e.target.value)}
                                            inputMode="numeric"
                                        />
                                    </div>
                                </Field>

                                <Field label={t('profile.gender')} error={form.errors.gender}>
                                    <div className="relative">
                                        <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                                            </svg>
                                        </span>
                                        <select className={inputCls + ' pl-9 pr-8 appearance-none'} value={form.data.gender} onChange={(e) => form.setData('gender', e.target.value)}>
                                            <option value="">{t('profile.selectGender')}</option>
                                            <option value="male">{t('profile.genderMale')}</option>
                                            <option value="female">{t('profile.genderFemale')}</option>
                                        </select>
                                        <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-gray-400">
                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </span>
                                    </div>
                                </Field>

                                <div className="sm:col-span-2">
                                    <Field label={t('profile.nationalId')} error={form.errors.national_id} hint="16-digit national ID number">
                                        <div className="relative">
                                            <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M15 9h3.75M15 12h3.75M15 15h3.75M4.5 19.5h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5zm6-10.125a1.875 1.875 0 11-3.75 0 1.875 1.875 0 013.75 0zm1.294 6.336a6.721 6.721 0 01-3.17.789 6.721 6.721 0 01-3.17-.789 3.376 3.376 0 016.34 0z" />
                                                </svg>
                                            </span>
                                            <input
                                                className={inputCls + ' pl-9 font-mono tracking-widest'}
                                                placeholder="XXXX XXXX XXXX XXXX"
                                                value={nidDisplay}
                                                onChange={(e) => handleNationalId(e.target.value)}
                                                inputMode="numeric"
                                                maxLength={19}
                                            />
                                        </div>
                                    </Field>
                                </div>
                            </div>

                            {mustVerifyEmail && status === 'verification-link-sent' && (
                                <div className="mt-4 rounded-card bg-green-50 px-4 py-3 text-sm text-green-700 dark:bg-green-950/30 dark:text-green-400">
                                    {t('profile.verificationLinkSent')}
                                </div>
                            )}

                            <div className="mt-6 flex items-center justify-end gap-3 border-t border-gray-100 pt-5 dark:border-slate-800">
                                <Link
                                    href={route('dashboard')}
                                    className="rounded-card border border-gray-200 px-4 py-2.5 text-sm font-medium text-gray-600 transition hover:bg-gray-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800"
                                >
                                    {t('common.cancel')}
                                </Link>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="inline-flex items-center gap-2 rounded-card bg-[color:var(--color-primary)] px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[color:var(--color-primary-hover)] disabled:opacity-60"
                                >
                                    {form.processing && (
                                        <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                            <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                                        </svg>
                                    )}
                                    {form.processing ? t('common.saving') : t('profile.updateProfile')}
                                </button>
                            </div>
                        </form>
                    </div>

                    {/* Change password */}
                    <div className="rounded-panel border border-gray-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                        <div className="border-b border-gray-100 px-6 py-5 dark:border-slate-800">
                            <SectionHeader
                                icon={
                                    <svg className="h-4.5 w-4.5" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                    </svg>
                                }
                                title={t('profile.securityPassword')}
                                subtitle={t('profile.passwordHelp')}
                            />
                        </div>
                        <div className="p-6">
                            <UpdatePasswordForm />
                        </div>
                    </div>

                </div>
            </div>
        </AuthenticatedLayout>
    );
}
