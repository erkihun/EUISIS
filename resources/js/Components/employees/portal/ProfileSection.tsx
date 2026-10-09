import { Link, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import LocalizedDateDisplay from '@/Components/Calendar/LocalizedDateDisplay';
import { useConfirm } from '@/hooks/useConfirm';
import ContactChange from './ContactChange';
import InfoList from './InfoList';
import { Block, FieldErrors, PrintedChip, inputCls, labelCls, linkBtn, primaryBtn, secondaryBtn } from './ui';
import type { FieldPolicy, SelfServiceProfile } from './types';

type Translate = (path: string, params?: Record<string, string>) => string;
type Pick = (en: string | null | undefined, am: string | null | undefined) => string;

/** Directly editable, card-independent unless the printed card says otherwise. */
const DIRECT_FIELDS = ['address', 'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship'] as const;
type DirectField = (typeof DIRECT_FIELDS)[number];

/**
 * My Profile.
 *
 * Three visibly separate blocks, never one mixed form:
 *   personal block        - editable here (photo, contacts, address, emergency contact, preferences)
 *   official employment   - read only, maintained by HR
 *   identity block        - read only, changed through a correction request
 *
 * Whether a field is printed on the employee's current card comes from the
 * server (the card's print snapshot), never from a hard-coded list; a save
 * that touches a printed field is confirmed first.
 */
export default function ProfileSection({ profile, policy, l, pick }: {
    profile: SelfServiceProfile;
    policy: FieldPolicy;
    l: Translate;
    pick: Pick;
}) {
    const { confirm } = useConfirm();
    const text = (value: unknown) => (typeof value === 'string' ? value : '');
    const printed = (field: string) => Boolean(policy[field]?.card_visible);

    const initial = useMemo(() => ({
        address: text(profile.address),
        emergency_contact_name: text(profile.emergency_contact_name),
        emergency_contact_phone: text(profile.emergency_contact_phone),
        emergency_contact_relationship: text(profile.emergency_contact_relationship),
        preferred_language: text(profile.preferred_language) || 'en',
        notification_preferences: { email: profile.notification_preferences?.email ?? true },
        photo: null as File | null,
    }), [profile]);

    const form = useForm(initial);
    const [preview, setPreview] = useState<string | null>(null);

    // Object URLs hold memory until revoked.
    useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);

    function choosePhoto(file: File | null) {
        form.setData('photo', file);
        setPreview(file ? URL.createObjectURL(file) : null);
    }

    const changedPrinted = [
        ...(form.data.photo && printed('photo') ? ['photo'] : []),
        ...DIRECT_FIELDS.filter((field) => form.data[field] !== initial[field] && printed(field)),
    ];

    async function save(event: React.FormEvent) {
        event.preventDefault();

        if (changedPrinted.length > 0) {
            const { confirmed } = await confirm({
                title: l('confirm_card_title'),
                description: l('confirm_card_body', { fields: changedPrinted.map((field) => l(`fields.${field}`)).join(', ') }),
                confirmLabel: l('confirm_save'),
                cancelLabel: l('cancel'),
                variant: 'warning',
            });
            if (!confirmed) return;
        }

        // Only whitelisted fields leave the browser; the server rejects anything else.
        form.transform((data) => {
            const payload: Record<string, unknown> = {
                address: data.address,
                emergency_contact_name: data.emergency_contact_name,
                emergency_contact_phone: data.emergency_contact_phone,
                emergency_contact_relationship: data.emergency_contact_relationship,
                preferred_language: data.preferred_language,
                notification_preferences: { email: data.notification_preferences.email ? 1 : 0 },
            };
            if (data.photo) payload.photo = data.photo;
            return payload;
        });
        form.post('/my-portal/profile', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => choosePhoto(null),
        });
    }

    const photoSrc = preview ?? (typeof profile.photo_url === 'string' ? profile.photo_url : null);
    const initials = text(profile.full_name).split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

    const directInput = (field: DirectField, props: { type?: string; inputMode?: 'tel' | 'text'; maxLength: number; multiline?: boolean }) => (
        <div>
            <label htmlFor={`pf-${field}`} className={`${labelCls} flex flex-wrap items-center gap-1.5`}>
                {l(`fields.${field}`)}
                {printed(field) && <PrintedChip label={l('printed_on_card')} />}
            </label>
            {props.multiline ? (
                <textarea id={`pf-${field}`} rows={2} className={inputCls} maxLength={props.maxLength} value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)} />
            ) : (
                <input id={`pf-${field}`} type={props.type ?? 'text'} inputMode={props.inputMode} className={inputCls} maxLength={props.maxLength} value={form.data[field]} onChange={(e) => form.setData(field, e.target.value)} />
            )}
        </div>
    );

    const genderLabel = (value: unknown) => {
        const key = text(value);
        return key ? (l(`genders.${key}`) !== `genders.${key}` ? l(`genders.${key}`) : key) : '';
    };

    return (
        <div className="space-y-4">
            {/* Who this is: always the signed-in employee, never chosen by URL. */}
            <div className="flex items-center gap-3">
                {typeof profile.photo_url === 'string'
                    ? <img src={profile.photo_url} alt="" className="h-14 w-14 rounded-full object-cover ring-1 ring-gray-200 dark:ring-slate-700" />
                    : <span className="flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-lg font-semibold text-gray-600 dark:bg-slate-800 dark:text-slate-300">{initials || '—'}</span>}
                <div className="min-w-0">
                    <p className="truncate text-base font-semibold text-gray-900 dark:text-slate-100">{text(profile.full_name)}</p>
                    <p className="truncate text-sm text-gray-500 dark:text-slate-400">
                        {[text(profile.employee_number), pick(text(profile.position), text(profile.position_am))].filter(Boolean).join(' · ')}
                    </p>
                </div>
            </div>

            <form onSubmit={save}>
                <Block title={l('personal')} badge={l('editable')} badgeTone="editable">
                    <div className="space-y-5">
                        {/* Photo */}
                        <div className="flex flex-wrap items-center gap-4">
                            {photoSrc
                                ? <img src={photoSrc} alt={l('fields.photo')} className="h-24 w-20 rounded-lg object-cover ring-1 ring-gray-200 dark:ring-slate-700" />
                                : <span className="flex h-24 w-20 items-center justify-center rounded-lg bg-gray-100 text-xs text-gray-500 dark:bg-slate-800">{l('not_set')}</span>}
                            <div className="min-w-0 flex-1 space-y-1.5">
                                <p className="flex flex-wrap items-center gap-1.5 text-xs font-medium text-gray-600 dark:text-slate-400">
                                    {l('fields.photo')}
                                    {printed('photo') && <PrintedChip label={l('printed_on_card')} />}
                                </p>
                                <label className={`${secondaryBtn} min-h-9 cursor-pointer px-3 py-1.5`}>
                                    {l('choose_photo')}
                                    <input type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => choosePhoto(e.target.files?.[0] ?? null)} />
                                </label>
                                <p className="text-xs text-gray-500 dark:text-slate-400">{l('photo_help')}</p>
                                {form.data.photo && (
                                    <p className="text-xs text-gray-700 dark:text-slate-300">
                                        {l('photo_selected')}
                                        {printed('photo') && <span className="block text-amber-800 dark:text-amber-300">{l('card_warning')}</span>}
                                        <button type="button" onClick={() => choosePhoto(null)} className={`${linkBtn} ms-2 text-xs`}>{l('cancel')}</button>
                                    </p>
                                )}
                            </div>
                        </div>

                        {/* Verified contacts: changed through their own code flow, not this form. */}
                        <div>
                            <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{l('contact_details')}</h3>
                            <div className="mt-2 divide-y divide-gray-100 dark:divide-slate-800">
                                <ContactChange field="email" value={text(profile.email)} printed={printed('email')} l={l} />
                                <ContactChange field="phone" value={text(profile.phone)} printed={printed('phone')} l={l} />
                            </div>
                            <p className="mt-2 text-xs text-gray-500 dark:text-slate-400">{l('contact_note')}</p>
                        </div>

                        {directInput('address', { maxLength: 1000, multiline: true })}

                        <div>
                            <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{l('emergency_contact')}</h3>
                            <div className="mt-2 grid gap-3 sm:grid-cols-3">
                                {directInput('emergency_contact_name', { maxLength: 255 })}
                                {directInput('emergency_contact_phone', { type: 'tel', inputMode: 'tel', maxLength: 30 })}
                                {directInput('emergency_contact_relationship', { maxLength: 100 })}
                            </div>
                        </div>

                        <div>
                            <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-slate-400">{l('preferences')}</h3>
                            <div className="mt-2 grid gap-3 sm:grid-cols-2 sm:items-end">
                                <div>
                                    <label htmlFor="pf-language" className={labelCls}>{l('fields.preferred_language')}</label>
                                    <select id="pf-language" className={inputCls} value={form.data.preferred_language} onChange={(e) => form.setData('preferred_language', e.target.value)}>
                                        <option value="en">{l('languages.en')}</option>
                                        <option value="am">{l('languages.am')}</option>
                                    </select>
                                </div>
                                <label className="flex min-h-10 items-center gap-2 text-sm text-gray-800 dark:text-slate-200">
                                    <input type="checkbox" className="h-4 w-4 rounded border-gray-300" checked={form.data.notification_preferences.email} onChange={(e) => form.setData('notification_preferences', { email: e.target.checked })} />
                                    {l('fields.notification_preferences')}
                                </label>
                            </div>
                        </div>

                        <FieldErrors errors={form.errors as Record<string, string | undefined>} />

                        <div className="flex justify-end border-t border-gray-100 pt-3 dark:border-slate-800">
                            <button className={`${primaryBtn} w-full sm:w-auto`} disabled={form.processing || !form.isDirty}>{l('save')}</button>
                        </div>
                    </div>
                </Block>
            </form>

            <Block title={l('official')} badge={l('read_only')}>
                <InfoList
                    printedLabel={l('printed_on_card')}
                    rows={[
                        { key: 'employee_number', label: l('fields.employee_number'), value: text(profile.employee_number), printed: printed('employee_number') },
                        { key: 'employment_type', label: l('fields.employment_type'), value: pick(text(profile.employment_type), text(profile.employment_type_am)), printed: printed('employment_type') },
                        { key: 'organization', label: l('fields.organization'), value: pick(text(profile.organization), text(profile.organization_am)), printed: printed('organization') },
                        { key: 'organization_unit', label: l('fields.organization_unit'), value: pick(text(profile.organization_unit), text(profile.organization_unit_am)) },
                        { key: 'position', label: l('fields.position'), value: pick(text(profile.position), text(profile.position_am)), printed: printed('position') },
                        { key: 'job_grade', label: l('fields.job_grade'), value: text(profile.job_grade) },
                    ]}
                />
            </Block>

            <Block
                title={l('identity')}
                badge={l('read_only')}
                actions={<Link href="/my-portal/requests" className={linkBtn}>{l('correction')} →</Link>}
            >
                <InfoList
                    printedLabel={l('printed_on_card')}
                    rows={[
                        { key: 'full_name', label: l('fields.full_name'), value: text(profile.full_name), printed: printed('full_name') },
                        { key: 'date_of_birth', label: l('fields.date_of_birth'), value: text(profile.date_of_birth) ? <LocalizedDateDisplay value={text(profile.date_of_birth)} /> : '', printed: printed('date_of_birth') },
                        { key: 'gender', label: l('fields.gender'), value: genderLabel(profile.gender), printed: printed('gender') },
                        { key: 'nationality', label: l('fields.nationality'), value: text(profile.nationality), printed: printed('nationality') },
                        { key: 'national_id', label: l('fields.national_id'), value: <span className="font-mono">{text(profile.national_id)}</span> },
                    ]}
                />
            </Block>
        </div>
    );
}
