import { useRef, useState, type ChangeEvent, type FormEvent } from 'react';
import { Link, router, useForm } from '@inertiajs/react';
import StatusBadge from '@/Components/StatusBadge';
import UserAvatar from '@/Components/UserAvatar';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { CameraIcon, ClockIcon, InfoIcon, MonitorIcon, ShieldIcon, UserIcon, X } from '@/Components/Icons';
import { Alert, Button, Card, CardFooter, FieldError, FormDescription, FormField, Input, Select, buttonClassName, cx } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import { formatRelative, toDate } from '@/lib/relativeTime';
import SectionCard from './SectionCard';
import type { DevicesInfo, Profile, SecurityInfo } from './types';

// ── Constants ─────────────────────────────────────────────────────────────────

const COUNTRY_CODES = [
    { code: '+251', label: '+251 ET' },
    { code: '+1', label: '+1 US' },
    { code: '+44', label: '+44 GB' },
    { code: '+971', label: '+971 AE' },
    { code: '+966', label: '+966 SA' },
    { code: '+254', label: '+254 KE' },
    { code: '+255', label: '+255 TZ' },
    { code: '+256', label: '+256 UG' },
    { code: '+20', label: '+20 EG' },
    { code: '+27', label: '+27 ZA' },
    { code: '+49', label: '+49 DE' },
    { code: '+33', label: '+33 FR' },
    { code: '+86', label: '+86 CN' },
    { code: '+91', label: '+91 IN' },
];

// Matches ProfileUpdateRequest: image, jpg/jpeg/png/webp, max 4096 KB.
const PHOTO_ACCEPT = 'image/jpeg,image/png,image/webp';

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

type FormData = {
    name: string; email: string; phone_number: string;
    gender: string; national_id: string; profile_photo: File | null;
};

/**
 * The Account tab: who this is (photo, name, roles, state) and the personal
 * information form. One `useForm` serves both, because the photo is saved
 * with the same PATCH as the other fields.
 */
export default function AccountSection({
    profile, security, devices, mustVerifyEmail, status,
}: {
    profile: Profile;
    security: SecurityInfo;
    devices: DevicesInfo;
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { t, locale } = useLocale();
    const photoInput = useRef<HTMLInputElement>(null);
    const [photoPreview, setPhotoPreview] = useState<string | null>(null);
    // router.post (multipart with _method=PATCH) does not drive useForm's processing flag.
    const [saving, setSaving] = useState(false);

    const parsedPhone = parsePhone(profile.phone_number);
    const [phoneCountry, setPhoneCountry] = useState(parsedPhone.country);
    const [phoneLocal, setPhoneLocal]     = useState(parsedPhone.local);
    const [nidDisplay, setNidDisplay]     = useState(formatNationalId(profile.national_id ?? ''));

    const form = useForm<FormData>({
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
    function handlePhotoChange(e: ChangeEvent<HTMLInputElement>) {
        const file = e.target.files?.[0];
        if (!file) return;
        form.setData('profile_photo', file);
        const reader = new FileReader();
        reader.onload = (ev) => setPhotoPreview(ev.target?.result as string);
        reader.readAsDataURL(file);
    }
    function clearPhoto() {
        form.setData('profile_photo', null);
        setPhotoPreview(null);
        if (photoInput.current) photoInput.current.value = '';
    }
    function submit(e: FormEvent) {
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
            onStart: () => { setSaving(true); form.clearErrors(); },
            onError: (errors) => form.setError(errors as Partial<Record<keyof FormData, string>>),
            onSuccess: () => clearPhoto(),
            onFinish: () => setSaving(false),
        });
    }

    const currentPhoto = photoPreview ?? profile.profile_photo_url;
    const isActive     = profile.status === 'active';
    const statusLabel  = t(`common.${profile.status}`) === `common.${profile.status}` ? profile.status : t(`common.${profile.status}`);
    const lastLogin    = toDate(profile.last_login_at);
    const deviceCount  = devices.supported ? devices.sessions.length : null;

    return (
        <div className="space-y-6">
            {/* ── Identity: photo, name, roles, account state ─────────────── */}
            <Card className="p-0">
                <div className="flex flex-col items-center gap-5 p-(--card-padding) text-center sm:flex-row sm:items-start sm:text-left">
                    <div className="relative shrink-0">
                        <div className={cx(
                            'h-24 w-24 overflow-hidden rounded-full ring-4',
                            photoPreview ? 'ring-primary/40' : 'ring-(--app-surface-muted)',
                        )}>
                            <UserAvatar src={currentPhoto} name={form.data.name || profile.name} size={96} />
                        </div>
                        <button
                            type="button"
                            onClick={() => photoInput.current?.click()}
                            title={t('profile.changePhoto')}
                            aria-label={t('profile.changePhoto')}
                            className="absolute -bottom-0.5 -right-0.5 flex h-8 w-8 items-center justify-center rounded-full border-2 border-(--app-surface) bg-primary text-white shadow-xs transition hover:brightness-110 focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
                        >
                            <CameraIcon className="h-4 w-4" />
                        </button>
                    </div>

                    <div className="min-w-0 flex-1">
                        <h2 className="text-section-title wrap-break-word text-(--app-foreground)">{profile.name}</h2>
                        <p className="mt-0.5 break-all text-sm text-(--app-muted-foreground)">{profile.email}</p>

                        <div className="mt-3 flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                            <StatusBadge status={isActive ? 'active' : 'inactive'} label={statusLabel} />
                            {profile.roles.map((role) => (
                                <span key={role} className="rounded-control bg-(--app-surface-muted) px-2 py-0.5 text-xs font-medium text-(--app-foreground)">
                                    {role}
                                </span>
                            ))}
                        </div>

                        {/* The facts a person checks at a glance; the Security and Devices tabs hold the detail. */}
                        <dl className="mt-3 flex flex-wrap justify-center gap-x-5 gap-y-1.5 text-xs text-(--app-muted-foreground) sm:justify-start">
                            <div className="flex items-center gap-1.5">
                                <ClockIcon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <dt>{t('profile.lastLogin')}</dt>
                                <dd className="font-medium text-(--app-foreground)" title={lastLogin?.toLocaleString()}>
                                    {lastLogin ? formatRelative(profile.last_login_at, locale) : t('profile.notAvailable')}
                                </dd>
                            </div>
                            <div className="flex items-center gap-1.5">
                                <ShieldIcon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <dt>{t('profile.twoFactor')}</dt>
                                <dd className="font-medium text-(--app-foreground)">{security.two_factor_enabled ? t('profile.twoFactorOn') : t('profile.twoFactorOff')}</dd>
                            </div>
                            {deviceCount !== null && (
                                <div className="flex items-center gap-1.5">
                                    <MonitorIcon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    <dd className="font-medium text-(--app-foreground)">{deviceCount}</dd>
                                    <dt>{t('profile.devicesSignedIn')}</dt>
                                </div>
                            )}
                            {profile.created_at && (
                                <div className="flex items-center gap-1.5">
                                    <dt>{t('profile.accountCreated')}</dt>
                                    <dd className="font-medium text-(--app-foreground)"><LocalizedDateDisplay value={profile.created_at} /></dd>
                                </div>
                            )}
                        </dl>
                    </div>

                    <div className="flex shrink-0 flex-wrap justify-center gap-2">
                        <Button variant="outline" size="sm" icon={<CameraIcon className="h-4 w-4" />} onClick={() => photoInput.current?.click()}>
                            {photoPreview || profile.profile_photo_url ? t('profile.changePhoto') : t('profile.uploadPhoto')}
                        </Button>
                        {photoPreview && (
                            <Button variant="ghost" size="sm" icon={<X className="h-4 w-4" />} onClick={clearPhoto}>
                                {t('profile.removeSelectedPhoto')}
                            </Button>
                        )}
                    </div>
                    <input ref={photoInput} type="file" accept={PHOTO_ACCEPT} className="sr-only" tabIndex={-1} aria-hidden="true" onChange={handlePhotoChange} />
                </div>

                <div className="border-t border-(--app-border) px-(--card-padding) py-3">
                    {photoPreview ? (
                        <p className="text-helper font-medium text-primary" role="status">{t('profile.photoPending')}</p>
                    ) : (
                        <FormDescription>{t('profile.photoHint')}</FormDescription>
                    )}
                    <FieldError className="mt-1">{form.errors.profile_photo}</FieldError>
                </div>
            </Card>

            {/* ── Personal information ─────────────────────────────────────── */}
            <SectionCard
                id="profile-personal-title"
                icon={<UserIcon className="h-4.5 w-4.5" />}
                title={t('profile.personalInformation')}
                description={t('profile.personalInformationHelp')}
            >
                <form onSubmit={submit}>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField label={t('profile.name')} required error={form.errors.name}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} className="w-full" autoComplete="name" required aria-describedby={describedBy} aria-invalid={invalid || undefined}
                                    value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                            )}
                        </FormField>

                        <FormField label={t('profile.email')} required error={form.errors.email}>
                            {({ id, describedBy, invalid }) => (
                                <Input id={id} type="email" className="w-full" autoComplete="email" required aria-describedby={describedBy} aria-invalid={invalid || undefined}
                                    value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            )}
                        </FormField>

                        <FormField label={t('profile.phoneNumber')} error={form.errors.phone_number}>
                            {({ id, describedBy, invalid }) => (
                                <div className="flex gap-2">
                                    {/* The kit Select is always w-full; the wrapper sets the width. */}
                                    <div className="w-28 shrink-0">
                                        <Select
                                            aria-label={t('profile.countryCode')}
                                            className="tabular-nums"
                                            value={phoneCountry}
                                            onChange={(e) => handlePhoneCountry(e.target.value)}
                                        >
                                            {COUNTRY_CODES.map((cc) => (
                                                <option key={cc.code} value={cc.code}>{cc.label}</option>
                                            ))}
                                        </Select>
                                    </div>
                                    <Input
                                        id={id}
                                        className="min-w-0 flex-1 tabular-nums"
                                        placeholder="9xxxxxxxx"
                                        autoComplete="tel-national"
                                        inputMode="numeric"
                                        aria-describedby={describedBy}
                                        aria-invalid={invalid || undefined}
                                        value={phoneLocal}
                                        onChange={(e) => handlePhoneLocal(e.target.value)}
                                    />
                                </div>
                            )}
                        </FormField>

                        <FormField label={t('profile.gender')} error={form.errors.gender}>
                            {({ id, describedBy, invalid }) => (
                                <Select id={id} className="w-full" aria-describedby={describedBy} aria-invalid={invalid || undefined}
                                    value={form.data.gender} onChange={(e) => form.setData('gender', e.target.value)}>
                                    <option value="">{t('profile.selectGender')}</option>
                                    <option value="male">{t('profile.genderMale')}</option>
                                    <option value="female">{t('profile.genderFemale')}</option>
                                </Select>
                            )}
                        </FormField>

                        <FormField className="sm:col-span-2" label={t('profile.nationalId')} description={t('profile.nationalIdHint')} error={form.errors.national_id}>
                            {({ id, describedBy, invalid }) => (
                                <Input
                                    id={id}
                                    className="w-full font-mono tracking-widest sm:max-w-sm"
                                    placeholder="XXXX XXXX XXXX XXXX"
                                    inputMode="numeric"
                                    maxLength={19}
                                    aria-describedby={describedBy}
                                    aria-invalid={invalid || undefined}
                                    value={nidDisplay}
                                    onChange={(e) => handleNationalId(e.target.value)}
                                />
                            )}
                        </FormField>
                    </div>

                    {mustVerifyEmail && status === 'verification-link-sent' && (
                        <Alert tone="success" className="mt-5">{t('profile.verificationLinkSent')}</Alert>
                    )}

                    <CardFooter className="mt-6">
                        <Link href={route('dashboard')} className={buttonClassName({ variant: 'outline' })}>
                            {t('common.cancel')}
                        </Link>
                        <Button type="submit" loading={saving}>
                            {saving ? t('common.saving') : t('profile.updateProfile')}
                        </Button>
                    </CardFooter>
                </form>
            </SectionCard>

            {/* Self-service deletion is off on purpose; say so once, quietly. */}
            <p className="flex items-start gap-2 text-helper text-(--app-muted-foreground)">
                <InfoIcon className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>
                    <span className="font-medium text-(--app-foreground)">{t('profile.accountDeletionDisabled')}</span>{' '}
                    {t('profile.selfDeleteProtected')}
                </span>
            </p>
        </div>
    );
}
